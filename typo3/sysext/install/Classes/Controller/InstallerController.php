<?php
declare(strict_types = 1);
namespace TYPO3\CMS\Install\Controller;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\DriverManager;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Configuration\ConfigurationManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Crypto\PasswordHashing\Argon2iPasswordHash;
use TYPO3\CMS\Core\Crypto\PasswordHashing\BcryptPasswordHash;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashInterface;
use TYPO3\CMS\Core\Crypto\PasswordHashing\Pbkdf2PasswordHash;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PhpassPasswordHash;
use TYPO3\CMS\Core\Crypto\Random;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Schema\Exception\StatementException;
use TYPO3\CMS\Core\Database\Schema\SchemaMigrator;
use TYPO3\CMS\Core\Database\Schema\SqlReader;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\FormProtection\InstallToolFormProtection;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Package\PackageInterface;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;
use TYPO3\CMS\Install\Configuration\FeatureManager;
use TYPO3\CMS\Install\FolderStructure\DefaultFactory;
use TYPO3\CMS\Install\Service\EnableFileService;
use TYPO3\CMS\Install\Service\Exception\ConfigurationChangedException;
use TYPO3\CMS\Install\Service\LateBootService;
use TYPO3\CMS\Install\Service\SilentConfigurationUpgradeService;
use TYPO3\CMS\Install\SystemEnvironment\Check;
use TYPO3\CMS\Install\SystemEnvironment\SetupCheck;

/**
 * Install step controller, dispatcher class of step actions.
 * @internal This class is a specific controller implementation and is not considered part of the Public TYPO3 API.
 */
class InstallerController
{
    /**
     * Init action loads <head> with JS initiating further stuff
     *
     * @return ResponseInterface
     */
    public function initAction(): ResponseInterface
    {
        $bust = $GLOBALS['EXEC_TIME'];
        if (!Environment::getContext()->isDevelopment()) {
            $bust = GeneralUtility::hmac(TYPO3_version . Environment::getProjectPath());
        }
        $view = $this->initializeStandaloneView('Installer/Init.html');
        $view->assign('bust', $bust);
        return new HtmlResponse(
            $view->render(),
            200,
            [
                'Cache-Control' => 'no-cache, must-revalidate',
                'Pragma' => 'no-cache'
            ]
        );
    }

    /**
     * Main layout with progress bar, header
     *
     * @return ResponseInterface
     */
    public function mainLayoutAction(): ResponseInterface
    {
        $view = $this->initializeStandaloneView('Installer/MainLayout.html');
        return new JsonResponse([
            'success' => true,
            'html' => $view->render(),
        ]);
    }

    /**
     * Render "FIRST_INSTALL file need to exist" view
     *
     * @return ResponseInterface
     */
    public function showInstallerNotAvailableAction(): ResponseInterface
    {
        $view = $this->initializeStandaloneView('Installer/ShowInstallerNotAvailable.html');
        return new JsonResponse([
            'success' => true,
            'html' => $view->render(),
        ]);
    }

    /**
     * Check if "environment and folders" should be shown
     *
     * @return ResponseInterface
     */
    public function checkEnvironmentAndFoldersAction(): ResponseInterface
    {
        return new JsonResponse([
            'success' => @is_file(GeneralUtility::makeInstance(ConfigurationManager::class)->getLocalConfigurationFileLocation()),
        ]);
    }

    /**
     * Render "environment and folders"
     *
     * @return ResponseInterface
     */
    public function showEnvironmentAndFoldersAction(): ResponseInterface
    {
        $view = $this->initializeStandaloneView('Installer/ShowEnvironmentAndFolders.html');
        $systemCheckMessageQueue = new FlashMessageQueue('install');
        $checkMessages = (new Check())->getStatus();
        foreach ($checkMessages as $message) {
            $systemCheckMessageQueue->enqueue($message);
        }
        $setupCheckMessages = (new SetupCheck())->getStatus();
        foreach ($setupCheckMessages as $message) {
            $systemCheckMessageQueue->enqueue($message);
        }
        $folderStructureFactory = GeneralUtility::makeInstance(DefaultFactory::class);
        $structureFacade = $folderStructureFactory->getStructure();
        $structureMessageQueue = $structureFacade->getStatus();
        return new JsonResponse([
            'success' => true,
            'html' => $view->render(),
            'environmentStatusErrors' => $systemCheckMessageQueue->getAllMessages(FlashMessage::ERROR),
            'environmentStatusWarnings' => $systemCheckMessageQueue->getAllMessages(FlashMessage::WARNING),
            'structureErrors' => $structureMessageQueue->getAllMessages(FlashMessage::ERROR),
        ]);
    }

    /**
     * Create main folder layout, LocalConfiguration, PackageStates
     *
     * @return ResponseInterface
     */
    public function executeEnvironmentAndFoldersAction(): ResponseInterface
    {
        $folderStructureFactory = GeneralUtility::makeInstance(DefaultFactory::class);
        $structureFacade = $folderStructureFactory->getStructure();
        $structureFixMessageQueue = $structureFacade->fix();
        $errorsFromStructure = $structureFixMessageQueue->getAllMessages(FlashMessage::ERROR);

        if (@is_dir(Environment::getLegacyConfigPath())) {
            $configurationManager = GeneralUtility::makeInstance(ConfigurationManager::class);
            $configurationManager->createLocalConfigurationFromFactoryConfiguration();

            // Create a PackageStates.php with all packages activated marked as "part of factory default"
            if (!file_exists(Environment::getLegacyConfigPath() . '/PackageStates.php')) {
                $packageManager = GeneralUtility::makeInstance(PackageManager::class);
                $packages = $packageManager->getAvailablePackages();
                foreach ($packages as $package) {
                    if ($package instanceof PackageInterface
                        && $package->isPartOfFactoryDefault()
                    ) {
                        $packageManager->activatePackage($package->getPackageKey());
                    }
                }
                $packageManager->forceSortAndSavePackageStates();
            }
            $extensionConfiguration = new ExtensionConfiguration();
            $extensionConfiguration->synchronizeExtConfTemplateWithLocalConfigurationOfAllExtensions();

            return new JsonResponse([
                'success' => true,
            ]);
        }
        return new JsonResponse([
            'success' => false,
            'status' => $errorsFromStructure,
        ]);
    }

    /**
     * Check if trusted hosts pattern needs to be adjusted
     *
     * @return ResponseInterface
     */
    public function checkTrustedHostsPatternAction(): ResponseInterface
    {
        return new JsonResponse([
            'success' => GeneralUtility::hostHeaderValueMatchesTrustedHostsPattern($_SERVER['HTTP_HOST']),
        ]);
    }

    /**
     * Adjust trusted hosts pattern to '.*' if it does not match yet
     *
     * @return ResponseInterface
     */
    public function executeAdjustTrustedHostsPatternAction(): ResponseInterface
    {
        if (!GeneralUtility::hostHeaderValueMatchesTrustedHostsPattern($_SERVER['HTTP_HOST'])) {
            $configurationManager = new ConfigurationManager();
            $configurationManager->setLocalConfigurationValueByPath('SYS/trustedHostsPattern', '.*');
        }
        return new JsonResponse([
            'success' => true,
        ]);
    }

    /**
     * Execute silent configuration update. May be called multiple times until success = true is returned.
     *
     * @return ResponseInterface success = true if no change has been done
     */
    public function executeSilentConfigurationUpdateAction(): ResponseInterface
    {
        $silentUpdate = new SilentConfigurationUpgradeService();
        $success = true;
        try {
            $silentUpdate->execute();
        } catch (ConfigurationChangedException $e) {
            $success = false;
        }
        return new JsonResponse([
            'success' => $success,
        ]);
    }

    /**
     * Check if database connect step needs to be shown
     *
     * @return ResponseInterface
     */
    public function checkDatabaseConnectAction(): ResponseInterface
    {
        return new JsonResponse([
            'success' => $this->isDatabaseConnectSuccessful() && $this->isDatabaseConfigurationComplete(),
        ]);
    }

    /**
     * Show database connect step
     *
     * @return ResponseInterface
     */
    public function showDatabaseConnectAction(): ResponseInterface
    {
        $view = $this->initializeStandaloneView('Installer/ShowDatabaseConnect.html');
        $formProtection = FormProtectionFactory::get(InstallToolFormProtection::class);
        $hasAtLeastOneOption = false;
        $activeAvailableOption = '';
        if (extension_loaded('mysqli')) {
            $hasAtLeastOneOption = true;
            $view->assign('hasMysqliManualConfiguration', true);
            $mysqliManualConfigurationOptions = [
                'username' => $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['user'] ?? '',
                'password' => $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['password'] ?? '',
                'port' => $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['port'] ?? 3306,
            ];
            $host = $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['host'] ?? '127.0.0.1';
            if ($host === 'localhost') {
                $host = '127.0.0.1';
            }
            $mysqliManualConfigurationOptions['host'] = $host;
            $view->assign('mysqliManualConfigurationOptions', $mysqliManualConfigurationOptions);
            $activeAvailableOption = 'mysqliManualConfiguration';

            $view->assign('hasMysqliSocketManualConfiguration', true);
            $view->assign(
                'mysqliSocketManualConfigurationOptions',
                [
                    'username' => $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['user'] ?? '',
                    'password' => $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['password'] ?? '',
                    'socket' => $this->getDatabaseConfiguredMysqliSocket(),
                ]
            );
            if ($GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['driver'] === 'mysqli'
                && $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['host'] === 'localhost') {
                $activeAvailableOption = 'mysqliSocketManualConfiguration';
            }
        }
        if (extension_loaded('pdo_pgsql')) {
            $hasAtLeastOneOption = true;
            $view->assign('hasPostgresManualConfiguration', true);
            $view->assign(
                'postgresManualConfigurationOptions',
                [
                    'username' => $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['user'] ?? '',
                    'password' => $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['password'] ?? '',
                    'host' => $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['host'] ?? '127.0.0.1',
                    'port' => $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['port'] ?? 5432,
                    'database' => $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['dbname'] ?? '',
                ]
            );
            if ($GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['driver'] === 'pdo_pgsql') {
                $activeAvailableOption = 'postgresManualConfiguration';
            }
        }
        if (extension_loaded('pdo_sqlite')) {
            $hasAtLeastOneOption = true;
            $view->assign('hasSqliteManualConfiguration', true);
            $view->assign(
                'sqliteManualConfigurationOptions',
                []
            );
            if ($GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['driver'] === 'pdo_sqlite') {
                $activeAvailableOption = 'sqliteManualConfiguration';
            }
        }

        if (!empty($this->getDatabaseConfigurationFromEnvironment())) {
            $hasAtLeastOneOption = true;
            $activeAvailableOption = 'configurationFromEnvironment';
            $view->assign('hasConfigurationFromEnvironment', true);
        }

        $view->assignMultiple([
            'hasAtLeastOneOption' => $hasAtLeastOneOption,
            'activeAvailableOption' => $activeAvailableOption,
            'executeDatabaseConnectToken' => $formProtection->generateToken('installTool', 'executeDatabaseConnect'),
        ]);

        return new JsonResponse([
            'success' => true,
            'html' => $view->render(),
        ]);
    }

    /**
     * Test database connect data
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function executeDatabaseConnectAction(ServerRequestInterface $request): ResponseInterface
    {
        $messages = [];
        $postValues = $request->getParsedBody()['install']['values'];
        $defaultConnectionSettings = [];

        if ($postValues['availableSet'] === 'configurationFromEnvironment') {
            $defaultConnectionSettings = $this->getDatabaseConfigurationFromEnvironment();
        } else {
            if (isset($postValues['driver'])) {
                $validDrivers = [
                    'mysqli',
                    'pdo_mysql',
                    'pdo_pgsql',
                    'mssql',
                    'pdo_sqlite',
                ];
                if (in_array($postValues['driver'], $validDrivers, true)) {
                    $defaultConnectionSettings['driver'] = $postValues['driver'];
                } else {
                    $messages[] = new FlashMessage(
                        'Given driver must be one of ' . implode(', ', $validDrivers),
                        'Database driver unknown',
                        FlashMessage::ERROR
                    );
                }
            }
            if (isset($postValues['username'])) {
                $value = $postValues['username'];
                if (strlen($value) <= 50) {
                    $defaultConnectionSettings['user'] = $value;
                } else {
                    $messages[] = new FlashMessage(
                        'Given username must be shorter than fifty characters.',
                        'Database username not valid',
                        FlashMessage::ERROR
                    );
                }
            }
            if (isset($postValues['password'])) {
                $defaultConnectionSettings['password'] = $postValues['password'];
            }
            if (isset($postValues['host'])) {
                $value = $postValues['host'];
                if (preg_match('/^[a-zA-Z0-9_\\.-]+(:.+)?$/', $value) && strlen($value) <= 255) {
                    $defaultConnectionSettings['host'] = $value;
                } else {
                    $messages[] = new FlashMessage(
                        'Given host is not alphanumeric (a-z, A-Z, 0-9 or _-.:) or longer than 255 characters.',
                        'Database host not valid',
                        FlashMessage::ERROR
                    );
                }
            }
            if (isset($postValues['port']) && $postValues['host'] !== 'localhost') {
                $value = $postValues['port'];
                if (preg_match('/^[0-9]+(:.+)?$/', $value) && $value > 0 && $value <= 65535) {
                    $defaultConnectionSettings['port'] = (int)$value;
                } else {
                    $messages[] = new FlashMessage(
                        'Given port is not numeric or within range 1 to 65535.',
                        'Database port not valid',
                        FlashMessage::ERROR
                    );
                }
            }
            if (isset($postValues['socket']) && $postValues['socket'] !== '') {
                if (@file_exists($postValues['socket'])) {
                    $defaultConnectionSettings['unix_socket'] = $postValues['socket'];
                } else {
                    $messages[] = new FlashMessage(
                        'Given socket location does not exist on server.',
                        'Socket does not exist',
                        FlashMessage::ERROR
                    );
                }
            }
            if (isset($postValues['database'])) {
                $value = $postValues['database'];
                if (strlen($value) <= 50) {
                    $defaultConnectionSettings['dbname'] = $value;
                } else {
                    $messages[] = new FlashMessage(
                        'Given database name must be shorter than fifty characters.',
                        'Database name not valid',
                        FlashMessage::ERROR
                    );
                }
            }
            // For sqlite a db path is automatically calculated
            if (isset($postValues['driver']) && $postValues['driver'] === 'pdo_sqlite') {
                $dbFilename = '/cms-' . (new Random())->generateRandomHexString(8) . '.sqlite';
                // If the var/ folder exists outside of document root, put it into var/sqlite/
                // Otherwise simply into typo3conf/
                if (Environment::getProjectPath() !== Environment::getPublicPath()) {
                    GeneralUtility::mkdir_deep(Environment::getVarPath() . '/sqlite');
                    $defaultConnectionSettings['path'] = Environment::getVarPath() . '/sqlite' . $dbFilename;
                } else {
                    $defaultConnectionSettings['path'] = Environment::getConfigPath() . $dbFilename;
                }
            }
            // For mysql, set utf8mb4 as default charset
            if (isset($postValues['driver']) && in_array($postValues['driver'], ['mysqli', 'pdo_mysql'])) {
                $defaultConnectionSettings['charset'] = 'utf8mb4';
                $defaultConnectionSettings['tableoptions'] = [
                    'charset' => 'utf8mb4',
                    'collate' => 'utf8mb4_unicode_ci',
                ];
            }
        }

        $success = false;
        if (!empty($defaultConnectionSettings)) {
            // Test connection settings and write to config if connect is successful
            try {
                $connectionParams = $defaultConnectionSettings;
                $connectionParams['wrapperClass'] = Connection::class;
                if (!isset($connectionParams['charset'])) {
                    // utf-8 as default for non mysql
                    $connectionParams['charset'] = 'utf-8';
                }
                DriverManager::getConnection($connectionParams)->ping();
                $success = true;
            } catch (DBALException $e) {
                $messages[] = new FlashMessage(
                    'Connecting to the database with given settings failed: ' . $e->getMessage(),
                    'Database connect not successful',
                    FlashMessage::ERROR
                );
            }
            $localConfigurationPathValuePairs = [];
            foreach ($defaultConnectionSettings as $settingsName => $value) {
                $localConfigurationPathValuePairs['DB/Connections/Default/' . $settingsName] = $value;
            }
            $configurationManager = GeneralUtility::makeInstance(ConfigurationManager::class);
            // Remove full default connection array
            $configurationManager->removeLocalConfigurationKeysByPath(['DB/Connections/Default']);
            // Write new values
            $configurationManager->setLocalConfigurationValuesByPathValuePairs($localConfigurationPathValuePairs);
        }

        return new JsonResponse([
            'success' => $success,
            'status' => $messages,
        ]);
    }

    /**
     * Check if a database needs to be selected
     *
     * @return ResponseInterface
     */
    public function checkDatabaseSelectAction(): ResponseInterface
    {
        $success = false;
        if ((string)$GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['dbname'] !== ''
            || (string)$GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['path'] !== ''
        ) {
            try {
                $success = GeneralUtility::makeInstance(ConnectionPool::class)
                    ->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME)
                    ->ping();
            } catch (DBALException $e) {
            }
        }
        return new JsonResponse([
            'success' => $success,
        ]);
    }

    /**
     * Render "select a database"
     *
     * @return ResponseInterface
     */
    public function showDatabaseSelectAction(): ResponseInterface
    {
        $view = $this->initializeStandaloneView('Installer/ShowDatabaseSelect.html');
        $formProtection = FormProtectionFactory::get(InstallToolFormProtection::class);
        $errors = [];
        try {
            $view->assign('databaseList', $this->getDatabaseList());
        } catch (\Exception $exception) {
            $errors[] = $exception->getMessage();
        }
        $view->assignMultiple([
            'errors' => $errors,
            'executeDatabaseSelectToken' => $formProtection->generateToken('installTool', 'executeDatabaseSelect'),
        ]);
        return new JsonResponse([
            'success' => true,
            'html' => $view->render(),
        ]);
    }

    /**
     * Select / create and test a database
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function executeDatabaseSelectAction(ServerRequestInterface $request): ResponseInterface
    {
        $postValues = $request->getParsedBody()['install']['values'];
        if ($postValues['type'] === 'new') {
            $status = $this->createNewDatabase($postValues['new']);
            if ($status->getSeverity() === FlashMessage::ERROR) {
                return new JsonResponse([
                    'success' => false,
                    'status' => [$status],
                ]);
            }
        } elseif ($postValues['type'] === 'existing' && !empty($postValues['existing'])) {
            $status = $this->checkExistingDatabase($postValues['existing']);
            if ($status->getSeverity() === FlashMessage::ERROR) {
                return new JsonResponse([
                    'success' => false,
                    'status' => [$status],
                ]);
            }
        } else {
            return new JsonResponse([
                'success' => false,
                'status' => [
                    new FlashMessage(
                        'You must select a database.',
                        'No Database selected',
                        FlashMessage::ERROR
                    ),
                ],
            ]);
        }
        return new JsonResponse([
            'success' => true,
        ]);
    }

    /**
     * Check if initial data needs to be imported
     *
     * @return ResponseInterface
     */
    public function checkDatabaseDataAction(): ResponseInterface
    {
        $existingTables = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionByName('Default')
            ->getSchemaManager()
            ->listTableNames();
        return new JsonResponse([
            'success' => !empty($existingTables),
        ]);
    }

    /**
     * Render "import initial data"
     *
     * @return ResponseInterface
     */
    public function showDatabaseDataAction(): ResponseInterface
    {
        $view = $this->initializeStandaloneView('Installer/ShowDatabaseData.html');
        $formProtection = FormProtectionFactory::get(InstallToolFormProtection::class);
        $view->assignMultiple([
            'siteName' => $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'],
            'executeDatabaseDataToken' => $formProtection->generateToken('installTool', 'executeDatabaseData'),
        ]);
        return new JsonResponse([
            'success' => true,
            'html' => $view->render(),
        ]);
    }

    /**
     * Create main db layout
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function executeDatabaseDataAction(ServerRequestInterface $request): ResponseInterface
    {
        $messages = [];
        $configurationManager = new ConfigurationManager();
        $postValues = $request->getParsedBody()['install']['values'];
        $username = (string)$postValues['username'] !== '' ? $postValues['username'] : 'admin';
        // Check password and return early if not good enough
        $password = $postValues['password'];
        $email = $postValues['email'] ?? '';
        if (empty($password) || strlen($password) < 8) {
            $messages[] = new FlashMessage(
                'You are setting an important password here! It gives an attacker full control over your instance if cracked.'
                . ' It should be strong (include lower and upper case characters, special characters and numbers) and must be at least eight characters long.',
                'Administrator password not secure enough!',
                FlashMessage::ERROR
            );
            return new JsonResponse([
                'success' => false,
                'status' => $messages,
            ]);
        }
        // Set site name
        if (!empty($postValues['sitename'])) {
            $configurationManager->setLocalConfigurationValueByPath('SYS/sitename', $postValues['sitename']);
        }
        try {
            $messages = $this->importDatabaseData();
            if (!empty($messages)) {
                return new JsonResponse([
                    'success' => false,
                    'status' => $messages,
                ]);
            }
        } catch (StatementException $exception) {
            $messages[] = new FlashMessage(
                'Error detected in SQL statement:' . LF . $exception->getMessage(),
                'Import of database data could not be performed',
                FlashMessage::ERROR
            );
            return new JsonResponse([
                'success' => false,
                'status' => $messages,
            ]);
        }
        // Insert admin user
        $adminUserFields = [
            'username' => $username,
            'password' => $this->getHashedPassword($password),
            'email' => GeneralUtility::validEmail($email) ? $email : '',
            'admin' => 1,
            'tstamp' => $GLOBALS['EXEC_TIME'],
            'crdate' => $GLOBALS['EXEC_TIME']
        ];
        $databaseConnection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('be_users');
        try {
            $databaseConnection->insert('be_users', $adminUserFields);
            $adminUserUid = (int)$databaseConnection->lastInsertId('be_users');
        } catch (DBALException $exception) {
            $messages[] = new FlashMessage(
                'The administrator account could not be created. The following error occurred:' . LF
                . $exception->getPrevious()->getMessage(),
                'Administrator account not created!',
                FlashMessage::ERROR
            );
            return new JsonResponse([
                'success' => false,
                'status' => $messages,
            ]);
        }
        // Set password as install tool password, add admin user to system maintainers
        $configurationManager->setLocalConfigurationValuesByPathValuePairs([
            'BE/installToolPassword' => $this->getHashedPassword($password),
            'SYS/systemMaintainers' => [$adminUserUid]
        ]);
        return new JsonResponse([
            'success' => true,
            'status' => $messages,
        ]);
    }

    /**
     * Show last "create empty site / install distribution"
     *
     * @return ResponseInterface
     */
    public function showDefaultConfigurationAction(): ResponseInterface
    {
        $view = $this->initializeStandaloneView('Installer/ShowDefaultConfiguration.html');
        $formProtection = FormProtectionFactory::get(InstallToolFormProtection::class);
        $view->assignMultiple([
            'composerMode' => Environment::isComposerMode(),
            'executeDefaultConfigurationToken' => $formProtection->generateToken('installTool', 'executeDefaultConfiguration'),
        ]);
        return new JsonResponse([
            'success' => true,
            'html' => $view->render(),
        ]);
    }

    /**
     * Last step execution: clean up, remove FIRST_INSTALL file, ...
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function executeDefaultConfigurationAction(ServerRequestInterface $request): ResponseInterface
    {
        $featureManager = new FeatureManager();
        // Get best matching configuration presets
        $configurationValues = $featureManager->getBestMatchingConfigurationForAllFeatures();
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);

        // Let the admin user redirect to the distributions page on first login
        switch ($request->getParsedBody()['install']['values']['sitesetup']) {
            // Update the admin backend user to show the distribution management on login
            case 'loaddistribution':
                $adminUserFirstLogin = [
                    'startModuleOnFirstLogin' => 'tools_ExtensionmanagerExtensionmanager->tx_extensionmanager_tools_extensionmanagerextensionmanager%5Baction%5D=distributions&tx_extensionmanager_tools_extensionmanagerextensionmanager%5Bcontroller%5D=List',
                    'ucSetByInstallTool' => '1',
                ];
                $connectionPool->getConnectionForTable('be_users')->update(
                    'be_users',
                    ['uc' => serialize($adminUserFirstLogin)],
                    ['admin' => 1]
                );
                break;

            // Create a page with UID 1 and PID1 and fluid_styled_content for page TS config, respect ownership
            case 'createsite':
                $databaseConnectionForPages = $connectionPool->getConnectionForTable('pages');
                $databaseConnectionForPages->insert(
                    'pages',
                    [
                        'pid' => 0,
                        'crdate' => time(),
                        'cruser_id' => 1,
                        'tstamp' => time(),
                        'title' => 'Home',
                        'slug' => '/',
                        'doktype' => 1,
                        'is_siteroot' => 1,
                        'perms_userid' => 1,
                        'perms_groupid' => 1,
                        'perms_user' => 31,
                        'perms_group' => 31,
                        'perms_everybody' => 1
                    ]
                );
                $pageUid = $databaseConnectionForPages->lastInsertId('pages');

                // add a root sys_template with fluid_styled_content and a default PAGE typoscript snippet
                $connectionPool->getConnectionForTable('sys_template')->insert(
                    'sys_template',
                    [
                        'pid' => $pageUid,
                        'crdate' => time(),
                        'cruser_id' => 1,
                        'tstamp' => time(),
                        'title' => 'Main TypoScript Rendering',
                        'sitetitle' => $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'],
                        'root' => 1,
                        'clear' => 1,
                        'include_static_file' => 'EXT:fluid_styled_content/Configuration/TypoScript/,EXT:fluid_styled_content/Configuration/TypoScript/Styling/',
                        'constants' => '',
                        'config' => 'page = PAGE
page.10 = TEXT
page.10.value (
   <div style="width: 800px; margin: 15% auto;">
      <div style="width: 300px;">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 150 42"><path d="M60.2 14.4v27h-3.8v-27h-6.7v-3.3h17.1v3.3h-6.6zm20.2 12.9v14h-3.9v-14l-7.7-16.2h4.1l5.7 12.2 5.7-12.2h3.9l-7.8 16.2zm19.5 2.6h-3.6v11.4h-3.8V11.1s3.7-.3 7.3-.3c6.6 0 8.5 4.1 8.5 9.4 0 6.5-2.3 9.7-8.4 9.7m.4-16c-2.4 0-4.1.3-4.1.3v12.6h4.1c2.4 0 4.1-1.6 4.1-6.3 0-4.4-1-6.6-4.1-6.6m21.5 27.7c-7.1 0-9-5.2-9-15.8 0-10.2 1.9-15.1 9-15.1s9 4.9 9 15.1c.1 10.6-1.8 15.8-9 15.8m0-27.7c-3.9 0-5.2 2.6-5.2 12.1 0 9.3 1.3 12.4 5.2 12.4 3.9 0 5.2-3.1 5.2-12.4 0-9.4-1.3-12.1-5.2-12.1m19.9 27.7c-2.1 0-5.3-.6-5.7-.7v-3.1c1 .2 3.7.7 5.6.7 2.2 0 3.6-1.9 3.6-5.2 0-3.9-.6-6-3.7-6H138V24h3.1c3.5 0 3.7-3.6 3.7-5.3 0-3.4-1.1-4.8-3.2-4.8-1.9 0-4.1.5-5.3.7v-3.2c.5-.1 3-.7 5.2-.7 4.4 0 7 1.9 7 8.3 0 2.9-1 5.5-3.3 6.3 2.6.2 3.8 3.1 3.8 7.3 0 6.6-2.5 9-7.3 9"/><path fill="#FF8700" d="M31.7 28.8c-.6.2-1.1.2-1.7.2-5.2 0-12.9-18.2-12.9-24.3 0-2.2.5-3 1.3-3.6C12 1.9 4.3 4.2 1.9 7.2 1.3 8 1 9.1 1 10.6c0 9.5 10.1 31 17.3 31 3.3 0 8.8-5.4 13.4-12.8M28.4.5c6.6 0 13.2 1.1 13.2 4.8 0 7.6-4.8 16.7-7.2 16.7-4.4 0-9.9-12.1-9.9-18.2C24.5 1 25.6.5 28.4.5"/></svg>
      </div>
      <h4 style="font-family: sans-serif;">Welcome to a default website made with <a href="https://typo3.org">TYPO3</a></h4>
   </div>
)
page.100 = CONTENT
page.100 {
    table = tt_content
    select {
        orderBy = sorting
        where = {#colPos}=0
    }
}
',
                        'description' => 'This is an Empty Site Package TypoScript template.

For each website you need a TypoScript template on the main page of your website (on the top level). For better maintenance all TypoScript should be extracted into external files via @import \'EXT:site_myproject/Configuration/TypoScript/setup.typoscript\''
                    ]
                );

                $this->createSiteConfiguration('main', (int)$pageUid, $request);
                break;
        }

        // Mark upgrade wizards as done
        $this->loadExtLocalconfDatabaseAndExtTables();
        if (!empty($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update'])) {
            $registry = GeneralUtility::makeInstance(Registry::class);
            foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update'] as $updateClassName) {
                $registry->set('installUpdate', $updateClassName, 1);
            }
        }

        $configurationManager = new ConfigurationManager();
        $configurationManager->setLocalConfigurationValuesByPathValuePairs($configurationValues);

        $formProtection = FormProtectionFactory::get(InstallToolFormProtection::class);
        $formProtection->clean();

        EnableFileService::removeFirstInstallFile();

        return new JsonResponse([
            'success' => true,
            'redirect' => GeneralUtility::getIndpEnv('TYPO3_SITE_URL') . TYPO3_mainDir . 'index.php',
        ]);
    }

    /**
     * Helper method to initialize a standalone view instance.
     *
     * @param string $templatePath
     * @return StandaloneView
     * @internal param string $template
     */
    protected function initializeStandaloneView(string $templatePath): StandaloneView
    {
        $viewRootPath = GeneralUtility::getFileAbsFileName('EXT:install/Resources/Private/');
        $view = GeneralUtility::makeInstance(StandaloneView::class);
        $view->getRequest()->setControllerExtensionName('Install');
        $view->setTemplatePathAndFilename($viewRootPath . 'Templates/' . $templatePath);
        $view->setLayoutRootPaths([$viewRootPath . 'Layouts/']);
        $view->setPartialRootPaths([$viewRootPath . 'Partials/']);
        return $view;
    }

    /**
     * Test connection with given credentials and return exception message if exception thrown
     *
     * @return bool
     */
    protected function isDatabaseConnectSuccessful(): bool
    {
        try {
            GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionByName('Default')->ping();
        } catch (DBALException $e) {
            return false;
        }
        return true;
    }

    /**
     * Check LocalConfiguration.php for required database settings:
     * - 'username' and 'password' are mandatory, but may be empty
     * - if 'driver' is pdo_sqlite and 'path' is set, its ok, too
     *
     * @return bool TRUE if required settings are present
     */
    protected function isDatabaseConfigurationComplete()
    {
        $configurationComplete = true;
        if (!isset($GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['user'])) {
            $configurationComplete = false;
        }
        if (!isset($GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['password'])) {
            $configurationComplete = false;
        }
        if (isset($GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['driver'])
            && $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['driver'] === 'pdo_sqlite'
            && !empty($GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['path'])
        ) {
            $configurationComplete = true;
        }
        return $configurationComplete;
    }

    /**
     * Returns configured socket, if set.
     *
     * @return string
     */
    protected function getDatabaseConfiguredMysqliSocket()
    {
        $socket = $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['unix_socket'] ?? '';
        if ($socket === '') {
            // If no configured socket, use default php socket
            $defaultSocket = (string)ini_get('mysqli.default_socket');
            if ($defaultSocket !== '') {
                $socket = $defaultSocket;
            }
        }
        return $socket;
    }

    /**
     * Try to fetch db credentials from a .env file and see if connect works
     *
     * @return array Empty array if no file is found or connect is not successful, else working credentials
     */
    protected function getDatabaseConfigurationFromEnvironment(): array
    {
        $envCredentials = [];
        foreach (['driver', 'host', 'user', 'password', 'port', 'dbname', 'unix_socket'] as $value) {
            $envVar = 'TYPO3_INSTALL_DB_' . strtoupper($value);
            if (getenv($envVar) !== false) {
                $envCredentials[$value] = getenv($envVar);
            }
        }
        if (!empty($envCredentials)) {
            $connectionParams = $envCredentials;
            $connectionParams['wrapperClass'] = Connection::class;
            $connectionParams['charset'] = 'utf-8';
            try {
                DriverManager::getConnection($connectionParams)->ping();
                return $envCredentials;
            } catch (DBALException $e) {
                return [];
            }
        }
        return [];
    }

    /**
     * Returns list of available databases (with access-check based on username/password)
     *
     * @return array List of available databases
     */
    protected function getDatabaseList()
    {
        $connectionParams = $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections'][ConnectionPool::DEFAULT_CONNECTION_NAME];
        unset($connectionParams['dbname']);

        // Establishing the connection using the Doctrine DriverManager directly
        // as we need a connection without selecting a database right away. Otherwise
        // an invalid database name would lead to exceptions which would prevent
        // changing the currently configured database.
        $connection = DriverManager::getConnection($connectionParams);
        $databaseArray = $connection->getSchemaManager()->listDatabases();
        $connection->close();

        // Remove organizational tables from database list
        $reservedDatabaseNames = ['mysql', 'information_schema', 'performance_schema'];
        $allPossibleDatabases = array_diff($databaseArray, $reservedDatabaseNames);

        // In first installation we show all databases but disable not empty ones (with tables)
        $databases = [];
        foreach ($allPossibleDatabases as $databaseName) {
            // Reestablishing the connection for each database since there is no
            // portable way to switch databases on the same Doctrine connection.
            // Directly using the Doctrine DriverManager here to avoid messing with
            // the $GLOBALS database configuration array.
            $connectionParams['dbname'] = $databaseName;
            $connection = DriverManager::getConnection($connectionParams);

            $databases[] = [
                'name' => $databaseName,
                'tables' => count($connection->getSchemaManager()->listTableNames()),
            ];
            $connection->close();
        }

        return $databases;
    }

    /**
     * Creates a new database on the default connection
     *
     * @param string $dbName name of database
     * @return FlashMessage
     */
    protected function createNewDatabase($dbName)
    {
        if (!$this->isValidDatabaseName($dbName)) {
            return new FlashMessage(
                'Given database name must be shorter than fifty characters'
                . ' and consist solely of basic latin letters (a-z), digits (0-9), dollar signs ($)'
                . ' and underscores (_).',
                'Database name not valid',
                FlashMessage::ERROR
            );
        }
        try {
            GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME)
                ->getSchemaManager()
                ->createDatabase($dbName);
            GeneralUtility::makeInstance(ConfigurationManager::class)
                ->setLocalConfigurationValueByPath('DB/Connections/Default/dbname', $dbName);
        } catch (DBALException $e) {
            return new FlashMessage(
                'Database with name "' . $dbName . '" could not be created.'
                . ' Either your database name contains a reserved keyword or your database'
                . ' user does not have sufficient permissions to create it or the database already exists.'
                . ' Please choose an existing (empty) database, choose another name or contact administration.',
                'Unable to create database',
                FlashMessage::ERROR
            );
        }
        return new FlashMessage(
            '',
            'Database created'
        );
    }

    /**
     * Validate the database name against the lowest common denominator of valid identifiers across different DBMS
     *
     * @param string $databaseName
     * @return bool
     */
    protected function isValidDatabaseName($databaseName)
    {
        return strlen($databaseName) <= 50 && preg_match('/^[a-zA-Z0-9\$_]*$/', $databaseName);
    }

    /**
     * Checks whether an existing database on the default connection
     * can be used for a TYPO3 installation. The database name is only
     * persisted to the local configuration if the database is empty.
     *
     * @param string $dbName name of the database
     * @return FlashMessage
     */
    protected function checkExistingDatabase($dbName)
    {
        $result = new FlashMessage('');
        $localConfigurationPathValuePairs = [];
        $configurationManager = GeneralUtility::makeInstance(ConfigurationManager::class);

        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections'][ConnectionPool::DEFAULT_CONNECTION_NAME]['dbname'] = $dbName;
        try {
            $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);

            if (!empty($connection->getSchemaManager()->listTableNames())) {
                $result = new FlashMessage(
                    sprintf('Cannot use database "%s"', $dbName)
                        . ', because it already contains tables. Please select a different database or choose to create one!',
                    'Selected database is not empty!',
                    FlashMessage::ERROR
                );
            }
        } catch (\Exception $e) {
            $result = new FlashMessage(
                sprintf('Could not connect to database "%s"', $dbName)
                    . '! Make sure it really exists and your database user has the permissions to select it!',
                'Could not connect to selected database!',
                FlashMessage::ERROR
            );
        }

        if ($result->getSeverity() === FlashMessage::OK) {
            $localConfigurationPathValuePairs['DB/Connections/Default/dbname'] = $dbName;
        }

        // check if database charset is utf-8 - also allow utf8mb4
        $defaultDatabaseCharset = $this->getDefaultDatabaseCharset($dbName);
        if (strpos($defaultDatabaseCharset, 'utf8') !== 0) {
            $result = new FlashMessage(
                'Your database uses character set "' . $defaultDatabaseCharset . '", '
                . 'but only "utf8" and "utf8mb4" are supported with TYPO3. You probably want to change this before proceeding.',
                'Invalid Charset',
                FlashMessage::ERROR
            );
        }

        if ($result->getSeverity() === FlashMessage::OK && !empty($localConfigurationPathValuePairs)) {
            $configurationManager->setLocalConfigurationValuesByPathValuePairs($localConfigurationPathValuePairs);
        }

        return $result;
    }

    /**
     * Retrieves the default character set of the database.
     *
     * @todo this function is MySQL specific. If the core has migrated to Doctrine it should be reexamined
     * whether this function and the check in $this->checkExistingDatabase could be deleted and utf8 otherwise
     * enforced (guaranteeing compatibility with other database servers).
     *
     * @param string $dbName
     * @return string
     */
    protected function getDefaultDatabaseCharset(string $dbName): string
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $queryBuilder = $connection->createQueryBuilder();
        $defaultDatabaseCharset = $queryBuilder->select('DEFAULT_CHARACTER_SET_NAME')
            ->from('information_schema.SCHEMATA')
            ->where(
                $queryBuilder->expr()->eq(
                    'SCHEMA_NAME',
                    $queryBuilder->createNamedParameter($dbName, \PDO::PARAM_STR)
                )
            )
            ->setMaxResults(1)
            ->execute()
            ->fetchColumn();

        return (string)$defaultDatabaseCharset;
    }

    /**
     * This function returns a salted hashed key for new backend user password and install tool password.
     *
     * This method is executed during installation *before* the preset did set up proper hash method
     * selection in LocalConfiguration. So PasswordHashFactory is not usable at this point. We thus loop through
     * the four default hash mechanisms and select the first one that works. The preset calculation of step
     * executeDefaultConfigurationAction() basically does the same later.
     *
     * @param string $password Plain text password
     * @return string Hashed password
     * @throws \LogicException If no hash method has been found, should never happen PhpassPasswordHash is always available
     */
    protected function getHashedPassword($password)
    {
        $okHashMethods = [
            Argon2iPasswordHash::class,
            BcryptPasswordHash::class,
            Pbkdf2PasswordHash::class,
            PhpassPasswordHash::class,
        ];
        foreach ($okHashMethods as $className) {
            /** @var PasswordHashInterface $instance */
            $instance = GeneralUtility::makeInstance($className);
            if ($instance->isAvailable()) {
                return $instance->getHashedPassword($password);
            }
        }
        throw new \LogicException('No suitable hash method found', 1533988846);
    }

    /**
     * Create tables and import static rows
     *
     * @return FlashMessage[]
     */
    protected function importDatabaseData()
    {
        // Will load ext_localconf and ext_tables. This is pretty safe here since we are
        // in first install (database empty), so it is very likely that no extension is loaded
        // that could trigger a fatal at this point.
        $container = $this->loadExtLocalconfDatabaseAndExtTables();

        $sqlReader = $container->get(SqlReader::class);
        $sqlCode = $sqlReader->getTablesDefinitionString(true);
        $schemaMigrationService = GeneralUtility::makeInstance(SchemaMigrator::class);
        $createTableStatements = $sqlReader->getCreateTableStatementArray($sqlCode);
        $results = $schemaMigrationService->install($createTableStatements);

        // Only keep statements with error messages
        $results = array_filter($results);
        if (count($results) === 0) {
            $insertStatements = $sqlReader->getInsertStatementArray($sqlCode);
            $results = $schemaMigrationService->importStaticData($insertStatements);
        }
        foreach ($results as $statement => &$message) {
            if ($message === '') {
                unset($results[$statement]);
                continue;
            }
            $message = new FlashMessage(
                'Query:' . LF . ' ' . $statement . LF . 'Error:' . LF . ' ' . $message,
                'Database query failed!',
                FlashMessage::ERROR
            );
        }
        return array_values($results);
    }

    /**
     * Some actions like the database analyzer and the upgrade wizards need additional
     * bootstrap actions performed.
     *
     * Those actions can potentially fatal if some old extension is loaded that triggers
     * a fatal in ext_localconf or ext_tables code! Use only if really needed.
     *
     * @return ContainerInterface
     */
    protected function loadExtLocalconfDatabaseAndExtTables(): ContainerInterface
    {
        return GeneralUtility::makeInstance(LateBootService::class)->loadExtLocalconfDatabaseAndExtTables();
    }

    /**
     * Creates a site configuration with one language "English" which is the de-facto default language for TYPO3 in general.
     *
     * @param string $identifier
     * @param int $rootPageId
     * @param ServerRequestInterface $request
     * @throws \TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException
     */
    protected function createSiteConfiguration(string $identifier, int $rootPageId, ServerRequestInterface $request)
    {
        $normalizedParams = $request->getAttribute('normalizedParams', null);
        if (!($normalizedParams instanceof NormalizedParams)) {
            $normalizedParams = new NormalizedParams(
                $request->getServerParams(),
                $GLOBALS['TYPO3_CONF_VARS']['SYS'],
                Environment::getCurrentScript(),
                Environment::getPublicPath()
            );
        }

        // Create a default site configuration called "main" as best practice
        $siteConfiguration = GeneralUtility::makeInstance(
            SiteConfiguration::class,
            Environment::getConfigPath() . '/sites'
        );
        $siteConfiguration->createNewBasicSite($identifier, $rootPageId, $normalizedParams->getSiteUrl());
    }
}
