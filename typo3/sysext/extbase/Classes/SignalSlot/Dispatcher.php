<?php
declare(strict_types = 1);

namespace TYPO3\CMS\Extbase\SignalSlot;

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

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Resource\Event\AfterFileAddedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileAddedToIndexEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileContentsSetEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileCopiedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileCreatedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileDeletedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileMarkedAsMissingEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileMetaDataCreatedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileMetaDataDeletedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileMetaDataUpdatedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileMovedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileProcessingEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileRemovedFromIndexEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileRenamedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileReplacedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileUpdatedInIndexEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFolderAddedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFolderCopiedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFolderDeletedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFolderMovedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFolderRenamedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterResourceStorageInitializationEvent;
use TYPO3\CMS\Core\Resource\Event\BeforeFileAddedEvent;
use TYPO3\CMS\Core\Resource\Event\BeforeFileContentsSetEvent;
use TYPO3\CMS\Core\Resource\Event\BeforeFileCopiedEvent;
use TYPO3\CMS\Core\Resource\Event\BeforeFileCreatedEvent;
use TYPO3\CMS\Core\Resource\Event\BeforeFileDeletedEvent;
use TYPO3\CMS\Core\Resource\Event\BeforeFileMovedEvent;
use TYPO3\CMS\Core\Resource\Event\BeforeFileProcessingEvent;
use TYPO3\CMS\Core\Resource\Event\BeforeFileRenamedEvent;
use TYPO3\CMS\Core\Resource\Event\BeforeFileReplacedEvent;
use TYPO3\CMS\Core\Resource\Event\BeforeFolderAddedEvent;
use TYPO3\CMS\Core\Resource\Event\BeforeFolderCopiedEvent;
use TYPO3\CMS\Core\Resource\Event\BeforeFolderDeletedEvent;
use TYPO3\CMS\Core\Resource\Event\BeforeFolderMovedEvent;
use TYPO3\CMS\Core\Resource\Event\BeforeFolderRenamedEvent;
use TYPO3\CMS\Core\Resource\Event\BeforeResourceStorageInitializationEvent;
use TYPO3\CMS\Core\Resource\Event\EnrichFileMetaDataEvent;
use TYPO3\CMS\Core\Resource\Event\GeneratePublicUrlForResourceEvent;
use TYPO3\CMS\Core\Resource\Event\SanitizeFileNameEvent;
use TYPO3\CMS\Core\Resource\Index\FileIndexRepository;
use TYPO3\CMS\Core\Resource\Index\MetaDataRepository;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceFactoryInterface;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\ResourceStorageInterface;
use TYPO3\CMS\Core\Resource\Service\FileProcessingService;
use TYPO3\CMS\Extbase\Object\ObjectManagerInterface;

/**
 * A dispatcher which dispatches signals by calling its registered slot methods
 * and passing them the method arguments which were originally passed to the
 * signal method.
 */
class Dispatcher implements \TYPO3\CMS\Core\SingletonInterface
{
    /**
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * Information about all slots connected a certain signal.
     * Indexed by [$signalClassName][$signalMethodName] and then numeric with an
     * array of information about the slot
     *
     * @var array
     */
    protected $slots = [];

    /**
     * @var LoggerInterface
     */
    protected $logger;

    protected $deprecatedSlots = [
        FileIndexRepository::class => [
            'recordUpdated' => AfterFileUpdatedInIndexEvent::class,
            'recordCreated' => AfterFileAddedToIndexEvent::class,
            'recordDeleted' => AfterFileRemovedFromIndexEvent::class,
            'recordMarkedAsMissing' => AfterFileMarkedAsMissingEvent::class
        ],
        MetaDataRepository::class => [
            'recordPostRetrieval' => EnrichFileMetaDataEvent::class,
            'recordUpdated' => AfterFileMetaDataUpdatedEvent::class,
            'recordCreated' => AfterFileMetaDataCreatedEvent::class,
            'recordDeleted' => AfterFileMetaDataDeletedEvent::class
        ],
        ResourceFactory::class => [
            ResourceFactoryInterface::SIGNAL_PreProcessStorage => BeforeResourceStorageInitializationEvent::class,
            ResourceFactoryInterface::SIGNAL_PostProcessStorage => AfterResourceStorageInitializationEvent::class,
        ],
        ResourceStorage::class => [
            ResourceStorageInterface::SIGNAL_SanitizeFileName => SanitizeFileNameEvent::class,
            ResourceStorageInterface::SIGNAL_PreFileAdd => BeforeFileAddedEvent::class,
            ResourceStorageInterface::SIGNAL_PostFileAdd => AfterFileAddedEvent::class,
            ResourceStorageInterface::SIGNAL_PreFileCopy => BeforeFileCopiedEvent::class,
            ResourceStorageInterface::SIGNAL_PostFileCopy => AfterFileCopiedEvent::class,
            ResourceStorageInterface::SIGNAL_PreFileMove => BeforeFileMovedEvent::class,
            ResourceStorageInterface::SIGNAL_PostFileMove => AfterFileMovedEvent::class,
            ResourceStorageInterface::SIGNAL_PreFileRename => BeforeFileRenamedEvent::class,
            ResourceStorageInterface::SIGNAL_PostFileRename => AfterFileRenamedEvent::class,
            ResourceStorageInterface::SIGNAL_PreFileReplace => BeforeFileReplacedEvent::class,
            ResourceStorageInterface::SIGNAL_PostFileReplace => AfterFileReplacedEvent::class,
            ResourceStorageInterface::SIGNAL_PreFileCreate => BeforeFileCreatedEvent::class,
            ResourceStorageInterface::SIGNAL_PostFileCreate => AfterFileCreatedEvent::class,
            ResourceStorageInterface::SIGNAL_PreFileDelete => BeforeFileDeletedEvent::class,
            ResourceStorageInterface::SIGNAL_PostFileDelete => AfterFileDeletedEvent::class,
            ResourceStorageInterface::SIGNAL_PreFileSetContents => BeforeFileContentsSetEvent::class,
            ResourceStorageInterface::SIGNAL_PostFileSetContents => AfterFileContentsSetEvent::class,
            ResourceStorageInterface::SIGNAL_PreFolderAdd => BeforeFolderAddedEvent::class,
            ResourceStorageInterface::SIGNAL_PostFolderAdd => AfterFolderAddedEvent::class,
            ResourceStorageInterface::SIGNAL_PreFolderCopy => BeforeFolderCopiedEvent::class,
            ResourceStorageInterface::SIGNAL_PostFolderCopy => AfterFolderCopiedEvent::class,
            ResourceStorageInterface::SIGNAL_PreFolderMove => BeforeFolderMovedEvent::class,
            ResourceStorageInterface::SIGNAL_PostFolderMove => AfterFolderMovedEvent::class,
            ResourceStorageInterface::SIGNAL_PreFolderRename => BeforeFolderRenamedEvent::class,
            ResourceStorageInterface::SIGNAL_PostFolderRename => AfterFolderRenamedEvent::class,
            ResourceStorageInterface::SIGNAL_PreFolderDelete => BeforeFolderDeletedEvent::class,
            ResourceStorageInterface::SIGNAL_PostFolderDelete => AfterFolderDeletedEvent::class,
            ResourceStorageInterface::SIGNAL_PreGeneratePublicUrl => GeneratePublicUrlForResourceEvent::class,
        ],
        FileProcessingService::class => [
            FileProcessingService::SIGNAL_PreFileProcess => BeforeFileProcessingEvent::class,
            FileProcessingService::SIGNAL_PostFileProcess => AfterFileProcessingEvent::class,
        ],
    ];

    /**
     * @param ObjectManagerInterface $objectManager
     * @param LoggerInterface $logger
     */
    public function __construct(ObjectManagerInterface $objectManager, LoggerInterface $logger)
    {
        $this->objectManager = $objectManager;
        $this->logger = $logger;
    }

    /**
     * Connects a signal with a slot.
     * One slot can be connected with multiple signals by calling this method multiple times.
     *
     * @param string $signalClassName Name of the class containing the signal
     * @param string $signalName Name of the signal
     * @param mixed $slotClassNameOrObject Name of the class containing the slot or the instantiated class or a Closure object
     * @param string $slotMethodName Name of the method to be used as a slot. If $slotClassNameOrObject is a Closure object, this parameter is ignored
     * @param bool $passSignalInformation If set to TRUE, the last argument passed to the slot will be information about the signal (EmitterClassName::signalName)
     * @throws \InvalidArgumentException
     */
    public function connect(string $signalClassName, string $signalName, $slotClassNameOrObject, string $slotMethodName = '', bool $passSignalInformation = true): void
    {
        $class = null;
        $object = null;
        if (is_object($slotClassNameOrObject)) {
            $object = $slotClassNameOrObject;
            $method = $slotClassNameOrObject instanceof \Closure ? '__invoke' : $slotMethodName;
        } else {
            if ($slotMethodName === '') {
                throw new \InvalidArgumentException('The slot method name must not be empty (except for closures).', 1229531659);
            }
            $class = $slotClassNameOrObject;
            $method = $slotMethodName;
        }
        $slot = [
            'class' => $class,
            'method' => $method,
            'object' => $object,
            'passSignalInformation' => $passSignalInformation === true,
        ];
        // The in_array() comparision needs to be strict to avoid potential issues
        // with complex objects being registered as slot.
        if (!is_array($this->slots[$signalClassName][$signalName] ?? false) || !in_array($slot, $this->slots[$signalClassName][$signalName], true)) {
            $this->slots[$signalClassName][$signalName][] = $slot;
        }
    }

    /**
     * Dispatches a signal by calling the registered Slot methods
     *
     * @param string $signalClassName Name of the class containing the signal
     * @param string $signalName Name of the signal
     * @param array $signalArguments arguments passed to the signal method
     * @return mixed
     * @throws Exception\InvalidSlotException if the slot is not valid
     * @throws Exception\InvalidSlotReturnException if a slot returns invalid arguments (too few or return value is not an array)
     */
    public function dispatch(string $signalClassName, string $signalName, array $signalArguments = [])
    {
        $this->logger->debug(
            'Triggered signal ' . $signalClassName . ' ' . $signalName,
            [
                'signalClassName' => $signalClassName,
                'signalName' => $signalName,
                'signalArguments' => $signalArguments,
            ]
        );
        if (!isset($this->slots[$signalClassName][$signalName])) {
            return $signalArguments;
        }
        foreach ($this->slots[$signalClassName][$signalName] as $slotInformation) {
            if (isset($slotInformation['object'])) {
                $object = $slotInformation['object'];
            } else {
                if (!$this->objectManager->isRegistered($slotInformation['class'])) {
                    throw new Exception\InvalidSlotException('The given class "' . $slotInformation['class'] . '" is not a registered object.', 1245673367);
                }
                $object = $this->objectManager->get($slotInformation['class']);
            }

            if (!method_exists($object, $slotInformation['method'])) {
                throw new Exception\InvalidSlotException('The slot method ' . get_class($object) . '->' . $slotInformation['method'] . '() does not exist.', 1245673368);
            }

            $preparedSlotArguments = $signalArguments;
            if ($slotInformation['passSignalInformation'] === true) {
                $preparedSlotArguments[] = $signalClassName . '::' . $signalName;
            }

            $slotReturn = call_user_func_array([$object, $slotInformation['method']], $preparedSlotArguments);

            if ($slotReturn) {
                if (!is_array($slotReturn)) {
                    throw new Exception\InvalidSlotReturnException('The slot method ' . get_class($object) . '->' . $slotInformation['method'] . '()\'s return value is of an not allowed type ('
                        . gettype($slotReturn) . ').', 1376683067);
                }
                if (count($slotReturn) !== count($signalArguments)) {
                    throw new Exception\InvalidSlotReturnException('The slot method ' . get_class($object) . '->' . $slotInformation['method'] . '() returned a different number ('
                        . count($slotReturn) . ') of arguments, than it received (' . count($signalArguments) . ').', 1376683066);
                }
                $signalArguments = $slotReturn;
            }
        }

        return $signalArguments;
    }

    /**
     * Returns all slots which are connected with the given signal
     *
     * @param string $signalClassName Name of the class containing the signal
     * @param string $signalName Name of the signal
     * @return array An array of arrays with slot information
     */
    public function getSlots(string $signalClassName, string $signalName): array
    {
        return $this->slots[$signalClassName][$signalName] ?? [];
    }

    /**
     * This method is called by TYPO3\CMS\Extbase\Middleware\SignalSlotDeprecator to collect and report
     * the deprecated slots only once per request.
     *
     * @internal
     */
    public function reportDeprecatedSignalSlots(): void
    {
        $messages = [];
        foreach ($this->slots as $signalClassName => $signals) {
            if (isset($this->deprecatedSlots[$signalClassName])) {
                foreach (array_keys($signals) as $signalName) {
                    $eventClass = $this->deprecatedSlots[$signalClassName][$signalName] ?? null;
                    if ($eventClass !== null) {
                        $messages[] = 'The signal "' . $signalName . '" in "' . $signalClassName . '" is deprecated and will stop working in TYPO3 11.0. Use the PSR-14 event: "' . $eventClass . '"';
                    }
                }
            }
        }
        if (count($messages)) {
            trigger_error(
                'The following deprecated signals are connected:' . LF .
                implode(LF, $messages),
                E_USER_DEPRECATED
            );
        }
    }
}
