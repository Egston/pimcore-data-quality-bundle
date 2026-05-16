<?php

namespace Basilicom\DataQualityBundle\Model\Listener;

use Basilicom\DataQualityBundle\Service\DataQualityService;
use Basilicom\DataQualityBundle\Service\DependencyResolver;
use Exception;
use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Event\Model\ElementEventInterface;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Tool\Admin;

/**
 * Pre-save data-quality recompute trigger.
 *
 * The save path's outer `catch (Exception)` (below) is a documented
 * logged-and-swallowed surface: any `DataQualityException` from
 * `DependencyResolver::sort` (cycles, duplicate producers, missing
 * producers) and any rule-level exception are caught so the admin save
 * completes even when DQ recompute is impossible. The failure is logged
 * at warning level; hardening to a loud failure is its own architectural
 * concern.
 */
class ObjectPreSaveListener
{
    private static bool $listenerEnabled = true;

    private DataQualityService $dataQualityService;

    private DependencyResolver $dependencyResolver;

    public function __construct(
        DataQualityService $dataQualityService,
        DependencyResolver $dependencyResolver
    ) {
        $this->dataQualityService = $dataQualityService;
        $this->dependencyResolver = $dependencyResolver;
    }

    public function onPreSave(ElementEventInterface $event)
    {
        try {
            $this->listenerIsEnabled();
            $this->isAutoSave($event->getArguments());
            $this->isEventOfCorrectType($event);

            $dataObject = $event->getElement();

            if (!($dataObject instanceof Concrete)) {
                throw new Exception('skip all but "real" data objects (no folders)');
            }

            $dataQualityConfigs = $this->dataQualityService->getDataQualityConfigs($dataObject);
            if (empty($dataQualityConfigs)) {
                return; // no data quality configurations
            }

            $sorted = $this->dependencyResolver->sort($dataQualityConfigs);

            self::withListenerDisabled(function () use ($dataObject, $sorted): void {
                foreach ($sorted as $dataQualityConfig) {
                    $isSystemAllowed = (bool) $dataQualityConfig->getDataQualitySystemAllowed();
                    if (!$isSystemAllowed && $this->isBackendUserActive()) {
                        continue;
                    }
                    $this->dataQualityService->calculateDataQuality($dataObject, $dataQualityConfig, false);
                }
            });
        } catch (Exception $exception) {
            if ($dataObject instanceof Concrete) {
                \Pimcore\Logger::warning(sprintf(
                    'DataQualityBundle: pre-save DQ recompute skipped for oo_id=%d: %s',
                    (int) $dataObject->getId(),
                    $exception->getMessage(),
                ), ['exception' => $exception]);
            }
        }
    }

    /**
     * Run $fn with the pre-save DQ recompute suppressed; try/finally
     * restores the previous state, including on throw. Suppression is
     * in-process and scope-blind — cascading save()s of other DQ-aware
     * objects are suppressed too for the duration of $fn.
     */
    public static function withListenerDisabled(callable $fn): mixed
    {
        $previous              = self::$listenerEnabled;
        self::$listenerEnabled = false;
        try {
            return $fn();
        } finally {
            self::$listenerEnabled = $previous;
        }
    }

    /**
     * @throws Exception
     */
    private function listenerIsEnabled(): void
    {
        if (!self::$listenerEnabled) {
            throw new Exception('skip if temporarily (in-process) disabled (to prevent recursion)');
        }
    }

    /**
     * @throws Exception
     */
    private function isAutoSave(array $arguments): void
    {
        if (isset($arguments['isAutoSave']) && $arguments['isAutoSave']) {
            throw new Exception('skip on autosave');
        }
    }

    /**
     * @throws Exception
     */
    private function isEventOfCorrectType(ElementEventInterface $event): void
    {
        if (!$event instanceof DataObjectEvent) {
            throw new Exception('wrong event type');
        }
    }

    private function isBackendUserActive(): bool
    {
        $userId = 0;
        $user   = Admin::getCurrentUser();
        if ($user) {
            $userId = $user->getId();
        }
        return $userId === 0;
    }
}
