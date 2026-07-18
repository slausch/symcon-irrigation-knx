<?php

declare(strict_types=1);

class WangariIrrigation extends IPSModule
{
    private const STATUS_ACTIVE = 102;
    private const STATUS_INACTIVE = 104;
    private const STATUS_INVALID_CONFIGURATION = 200;

    private const STATE_IDLE = 0;
    private const STATE_OPENING_MASTER = 1;
    private const STATE_RUNNING = 2;
    private const STATE_INTER_ZONE = 3;
    private const STATE_BLOCKED = 4;
    private const STATE_ERROR = 5;
    private const STATE_STOPPING = 6;
    private const STATE_PAUSED = 7;

    private const MESSAGE_VARIABLE_UPDATE = 10603;
    private const MAX_ZONES = 10;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Enabled', false);
        $this->RegisterPropertyBoolean('Simulation', true);
        $this->RegisterPropertyInteger('SimulationRuntimeMinutes', 1);
        $this->RegisterPropertyBoolean('AutomaticDefault', false);
        $this->RegisterPropertyString('MainValves', '[{"Enabled":false,"Name":"Irrigation pump","VariableID":0,"FeedbackID":0,"FeedbackInverted":false},{"Enabled":false,"Name":"Main valve","VariableID":0,"FeedbackID":0,"FeedbackInverted":false}]');
        $this->RegisterPropertyString('Zones', $this->defaultZonesJson());

        $this->RegisterPropertyString('StartTime', '05:00');
        $this->RegisterPropertyBoolean('Monday', true);
        $this->RegisterPropertyBoolean('Tuesday', true);
        $this->RegisterPropertyBoolean('Wednesday', true);
        $this->RegisterPropertyBoolean('Thursday', true);
        $this->RegisterPropertyBoolean('Friday', true);
        $this->RegisterPropertyBoolean('Saturday', true);
        $this->RegisterPropertyBoolean('Sunday', true);
        $this->RegisterPropertyInteger('IntervalDays', 1);
        $this->RegisterPropertyString('IntervalAnchor', '2025-01-01');

        $this->RegisterPropertyInteger('RainSensorID', 0);
        $this->RegisterPropertyBoolean('RainActiveValue', true);
        $this->RegisterPropertyInteger('SoilMoistureSensorID', 0);
        $this->RegisterPropertyFloat('SoilMoistureLimit', 0.0);
        $this->RegisterPropertyBoolean('SoilBlocksAboveLimit', true);

        // Kept for migration compatibility. New runs use PumpLeadSeconds and InterZoneSeconds.
        $this->RegisterPropertyInteger('MasterLeadSeconds', 2);
        $this->RegisterPropertyInteger('PumpLeadSeconds', 5);
        $this->RegisterPropertyInteger('InterZoneSeconds', 2);
        $this->RegisterPropertyInteger('FeedbackTimeoutSeconds', 10);
        $this->RegisterPropertyBoolean('MonitorClosedFeedback', false);
        $this->RegisterPropertyInteger('MaximumProgramMinutes', 180);
        $this->RegisterPropertyBoolean('LogOperations', true);

        $this->RegisterTimer('ScheduleTimer', 0, 'IRRKNX_CheckSchedule($_IPS["TARGET"]);');
        $this->RegisterTimer('RunTimer', 0, 'IRRKNX_Tick($_IPS["TARGET"]);');

        $this->registerProfiles();

        $this->RegisterVariableBoolean('Automatic', $this->Translate('Automatic operation'), $this->switchPresentation(), 10);
        $this->EnableAction('Automatic');
        $this->RegisterVariableInteger('IrrigationMode', $this->Translate('Irrigation mode'), 'IRRKNX.Mode', 15);
        $this->EnableAction('IrrigationMode');
        $this->RegisterVariableBoolean('ProgramActive', $this->Translate('Irrigation program'), $this->switchPresentation(), 20);
        $this->EnableAction('ProgramActive');
        $this->RegisterVariableBoolean('Pause', $this->Translate('Pause'), $this->switchPresentation(), 30);
        $this->EnableAction('Pause');
        $this->RegisterVariableBoolean('Skip', $this->Translate('Skip zone'), $this->actionPresentation($this->Translate('Skip')), 40);
        $this->EnableAction('Skip');
        $this->RegisterVariableBoolean('EmergencyStop', $this->Translate('Safety stop'), $this->actionPresentation($this->Translate('Safety stop')), 50);
        $this->EnableAction('EmergencyStop');

        $this->RegisterVariableInteger('ManualZone', $this->Translate('Manual zone'), 'IRRKNX.Zone', 100);
        $this->EnableAction('ManualZone');
        $this->RegisterVariableInteger('ManualRuntime', $this->Translate('Manual runtime'), $this->minutesPresentation(), 110);
        $this->EnableAction('ManualRuntime');

        $this->RegisterVariableInteger('PumpLeadSeconds', $this->Translate('Pump start delay'), $this->secondsPresentation(), 200);
        $this->EnableAction('PumpLeadSeconds');
        $this->RegisterVariableInteger('InterZoneSeconds', $this->Translate('Zone change delay'), $this->secondsPresentation(), 210);
        $this->EnableAction('InterZoneSeconds');
        $this->registerZoneControls();

        $this->RegisterVariableInteger('State', $this->Translate('State'), 'IRRKNX.State', 1000);
        $this->RegisterVariableInteger('CurrentZone', $this->Translate('Current zone'), 'IRRKNX.Zone', 1010);
        $this->RegisterVariableInteger('RemainingSeconds', $this->Translate('Remaining time'), 'IRRKNX.Seconds', 1020);
        $this->RegisterVariableBoolean('SensorBlocked', $this->Translate('Sensor active / blocking'), '~Alert', 1030);
        $this->RegisterVariableString('LastError', $this->Translate('Last error'), '', 1040);
        $this->RegisterVariableString('OutputState', $this->Translate('Output state'), '', 1050);
    }

    public function Migrate($JSONData)
    {
        parent::Migrate($JSONData);
        $data = json_decode((string) $JSONData);
        if (!is_object($data) || !isset($data->configuration) || !is_object($data->configuration)) {
            return '';
        }

        $changed = false;
        if (!isset($data->configuration->SimulationRuntimeMinutes)) {
            $data->configuration->SimulationRuntimeMinutes = 1;
            $changed = true;
        }
        if (!isset($data->configuration->PumpLeadSeconds)) {
            $data->configuration->PumpLeadSeconds = 5;
            $changed = true;
        }

        if (isset($data->configuration->Zones)) {
            $zones = json_decode((string) $data->configuration->Zones, true);
            if (is_array($zones)) {
                foreach ($zones as $index => &$zoneConfiguration) {
                    if (is_array($zoneConfiguration) && !array_key_exists('RainSensitive', $zoneConfiguration)) {
                        $zoneConfiguration['RainSensitive'] = true;
                        $changed = true;
                    }
                    if (is_array($zoneConfiguration) && !array_key_exists('Group', $zoneConfiguration)) {
                        $zoneConfiguration['Group'] = min(100, $index + 1);
                        $changed = true;
                    } elseif (is_array($zoneConfiguration) && ((int) $zoneConfiguration['Group'] > 100 || (int) $zoneConfiguration['Group'] < 1)) {
                        $zoneConfiguration['Group'] = max(1, min(100, (int) $zoneConfiguration['Group']));
                        $changed = true;
                    }
                }
                unset($zoneConfiguration);
                if (count($zones) < self::MAX_ZONES) {
                    for ($zone = count($zones) + 1; $zone <= self::MAX_ZONES; $zone++) {
                        $zones[] = $this->defaultZone($zone);
                    }
                    $changed = true;
                }
                $data->configuration->Zones = json_encode($zones);
            }
        }
        return $changed ? json_encode($data) : '';
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Register newly introduced runtime controls for existing instances as well.
        $this->RegisterVariableBoolean('Automatic', $this->Translate('Automatic operation'), $this->switchPresentation(), 10);
        $this->RegisterVariableInteger('IrrigationMode', $this->Translate('Irrigation mode'), 'IRRKNX.Mode', 15);
        $this->EnableAction('IrrigationMode');
        $this->RegisterVariableBoolean('ProgramActive', $this->Translate('Irrigation program'), $this->switchPresentation(), 20);
        $this->RegisterVariableBoolean('Pause', $this->Translate('Pause'), $this->switchPresentation(), 30);
        $this->EnableAction('Pause');
        $this->RegisterVariableBoolean('Skip', $this->Translate('Skip zone'), $this->actionPresentation($this->Translate('Skip')), 40);
        $this->EnableAction('Skip');
        $this->RegisterVariableBoolean('EmergencyStop', $this->Translate('Safety stop'), $this->actionPresentation($this->Translate('Safety stop')), 50);
        $this->EnableAction('EmergencyStop');
        $this->RegisterVariableInteger('ManualZone', $this->Translate('Manual zone'), 'IRRKNX.Zone', 100);
        $this->RegisterVariableInteger('ManualRuntime', $this->Translate('Manual runtime'), $this->minutesPresentation(), 110);
        $this->RegisterVariableInteger('PumpLeadSeconds', $this->Translate('Pump start delay'), $this->secondsPresentation(), 200);
        $this->EnableAction('PumpLeadSeconds');
        $this->RegisterVariableInteger('InterZoneSeconds', $this->Translate('Zone change delay'), $this->secondsPresentation(), 210);
        $this->EnableAction('InterZoneSeconds');
        $this->registerZoneControls();
        $this->RegisterVariableInteger('State', $this->Translate('State'), 'IRRKNX.State', 1000);
        $this->RegisterVariableInteger('CurrentZone', $this->Translate('Current zone'), 'IRRKNX.Zone', 1010);
        $this->RegisterVariableInteger('RemainingSeconds', $this->Translate('Remaining time'), 'IRRKNX.Seconds', 1020);
        $this->RegisterVariableBoolean('SensorBlocked', $this->Translate('Sensor active / blocking'), '~Alert', 1030);
        $this->RegisterVariableString('LastError', $this->Translate('Last error'), '', 1040);
        $this->RegisterVariableString('OutputState', $this->Translate('Output state'), '', 1050);
        $this->registerProfiles();
        $this->unsubscribeVariables();
        if ($this->isRunning()) {
            $this->safeShutdown($this->Translate('Recovered interrupted run or configuration change'), true);
        }
        $errors = $this->validateConfiguration();
        $warnings = $this->configurationWarnings();

        if ($this->GetBuffer('VariablesInitialized') !== '1') {
            $this->writeValue('Automatic', $this->ReadPropertyBoolean('AutomaticDefault'));
            $this->writeValue('ManualRuntime', 10);
            $this->setState(self::STATE_IDLE);
            $this->writeValue('CurrentZone', 0);
            $this->writeValue('RemainingSeconds', 0);
            $this->writeValue('LastError', '');
            $this->SetBuffer('VariablesInitialized', '1');
        }
        if ($this->GetBuffer('IrrigationModeInitialized') !== '1') {
            $this->writeValue('IrrigationMode', 1);
            $this->SetBuffer('IrrigationModeInitialized', '1');
        }
        // Applying form changes intentionally makes the form values authoritative.
        $this->writeValue('PumpLeadSeconds', max(0, min(300, $this->ReadPropertyInteger('PumpLeadSeconds'))));
        $this->writeValue('InterZoneSeconds', max(0, min(300, $this->ReadPropertyInteger('InterZoneSeconds'))));
        $this->initializeZoneControlsFromConfiguration();
        $this->updateZoneProfile();
        $this->initializeStateProfile();

        if (count($errors) > 0) {
            $this->SetTimerInterval('ScheduleTimer', 0);
            if ($this->GetBuffer('ShutdownPending') !== '1') {
                $this->SetTimerInterval('RunTimer', 0);
            }
            $this->writeValue('LastError', implode('; ', $errors));
            $this->SetStatus(self::STATUS_INVALID_CONFIGURATION);
            return;
        }

        if (count($warnings) > 0) {
            $warning = implode('; ', $warnings);
            $this->writeValue('LastError', $warning);
            $this->log($warning);
        }

        $this->subscribeVariables();
        $this->refreshSensorState();
        $this->refreshOutputState();

        if (!$this->ReadPropertyBoolean('Enabled')) {
            if ($this->isRunning()) {
                $this->safeShutdown($this->Translate('Module disabled'), false);
            }
            $this->SetTimerInterval('ScheduleTimer', 0);
            $this->SetStatus(self::STATUS_INACTIVE);
            return;
        }

        $this->SetTimerInterval('ScheduleTimer', 15000);
        $this->SetStatus(self::STATUS_ACTIVE);

    }

    public function RequestAction($Ident, $Value): void
    {
        switch ($Ident) {
            case 'Automatic':
                $this->writeValue('Automatic', (bool) $Value);
                break;

            case 'IrrigationMode':
                $this->SetIrrigationMode((int) $Value);
                break;

            case 'ProgramActive':
                if ((bool) $Value) {
                    $this->StartProgram(true);
                } else {
                    $this->Stop();
                }
                break;

            case 'ManualZone':
                $zone = (int) $Value;
                $this->writeValue('ManualZone', $zone);
                if ($zone === 0) {
                    $this->Stop();
                } else {
                    $this->StartZone($zone, $this->getValueInteger('ManualRuntime'));
                }
                break;

            case 'ManualRuntime':
                $this->writeValue('ManualRuntime', max(1, min(240, (int) $Value)));
                break;

            case 'PumpLeadSeconds':
                $this->writeValue('PumpLeadSeconds', max(0, min(300, (int) $Value)));
                break;

            case 'InterZoneSeconds':
                $this->writeValue('InterZoneSeconds', max(0, min(300, (int) $Value)));
                break;

            case 'Pause':
                if ((bool) $Value) {
                    if (!$this->Pause()) {
                        $this->writeValue('Pause', false);
                    }
                } elseif ($this->GetBuffer('Phase') === 'paused') {
                    if (!$this->Resume()) {
                        $this->writeValue('Pause', true);
                    }
                } else {
                    $this->writeValue('Pause', false);
                }
                break;

            case 'Skip':
                $this->writeValue('Skip', false);
                $this->SkipCurrentZone();
                break;

            case 'EmergencyStop':
                $this->writeValue('EmergencyStop', false);
                $this->EmergencyStop($this->Translate('Safety stop requested'));
                break;

            default:
                if (preg_match('/^Zone([1-9]|10)Automatic$/', (string) $Ident, $matches) === 1) {
                    $this->SetZoneAutomatic((int) $matches[1], (bool) $Value);
                    break;
                }
                if (preg_match('/^Zone([1-9]|10)Runtime$/', (string) $Ident, $matches) === 1) {
                    $this->SetZoneRuntime((int) $matches[1], (int) $Value);
                    break;
                }
                throw new InvalidArgumentException('Unknown action ident: ' . (string) $Ident);
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        if ((int) $Message !== self::MESSAGE_VARIABLE_UPDATE) {
            return;
        }

        $this->refreshSensorState();
        if ($this->GetBuffer('ShutdownPending') === '1') {
            $this->verifyShutdownFeedback();
            return;
        }
        if ($this->isRunning()) {
            if ($this->isSoilMoistureBlocked()) {
                $this->safeShutdown($this->Translate('Safety stop: soil moisture limit reached'), true);
                return;
            }
            if ($this->isRainActive() && $this->skipCurrentZoneDueToRain()) {
                return;
            }
            $this->refreshStateDisplay();
        }

        if ($this->isRunning() && !$this->feedbackMatchesExpected()) {
            $deadline = (int) $this->GetBuffer('FeedbackDeadline');
            if ($deadline > 0 && time() >= $deadline) {
                $this->safeShutdown($this->Translate('Safety stop: valve feedback mismatch'), true);
            }
        }
    }

    public function StartProgram(bool $Manual = true): bool
    {
        $mode = $this->getValueInteger('IrrigationMode');
        if ($mode === 0) {
            $this->setError($this->Translate('Irrigation mode is set to no watering'));
            return false;
        }

        $selectedZones = [];
        foreach ($this->getZones() as $index => $zone) {
            if ($this->zoneAutomatic($index + 1) && $this->zoneHasOutput($zone)) {
                $selectedZones[] = $index + 1;
            }
        }

        $queue = [];
        if ($mode === 2) {
            $groups = [];
            foreach ($selectedZones as $zoneNumber) {
                $groups[$this->zoneGroup($zoneNumber)][] = $zoneNumber;
            }
            ksort($groups, SORT_NUMERIC);
            $queue = array_values($groups);
        } else {
            foreach ($selectedZones as $zoneNumber) {
                $queue[] = [$zoneNumber];
            }
        }

        return $this->startQueue($queue, $Manual ? 'manual-program' : 'automatic', 0);
    }

    public function StartZone(int $Zone, int $RuntimeMinutes = 0): bool
    {
        if ($this->getValueInteger('IrrigationMode') === 0) {
            $this->setError($this->Translate('Irrigation mode is set to no watering'));
            return false;
        }
        if ($Zone < 1 || $Zone > self::MAX_ZONES) {
            $this->setError($this->Translate('Invalid zone number'));
            return false;
        }

        $zones = $this->getZones();
        $zone = $zones[$Zone - 1] ?? null;
        if (!is_array($zone) || !$this->zoneHasOutput($zone)) {
            $this->setError(sprintf($this->Translate('Zone %d has no configured valve'), $Zone));
            return false;
        }

        return $this->startQueue([[$Zone]], 'manual-zone', max(1, $RuntimeMinutes));
    }

    public function SetIrrigationMode(int $Mode): bool
    {
        $mode = max(0, min(2, $Mode));
        if ($this->isRunning() && $mode !== $this->getValueInteger('IrrigationMode')) {
            $this->safeShutdown($this->Translate('Irrigation mode changed'), false);
        }
        $this->writeValue('IrrigationMode', $mode);
        return true;
    }

    public function Stop(): void
    {
        $this->safeShutdown($this->Translate('Stopped by user'), false);
    }

    public function EmergencyStop(string $Reason = ''): void
    {
        $reason = $Reason !== '' ? $Reason : $this->Translate('Safety stop');
        $this->safeShutdown($reason, true);
    }

    public function SkipCurrentZone(): bool
    {
        if (!$this->isRunning()) {
            $this->setError($this->Translate('No irrigation run is active'));
            return false;
        }

        $phase = $this->GetBuffer('Phase');
        $preserveDeadline = false;
        if ($phase === 'running-zone') {
            $activeZones = $this->getActiveStepZones();
            if (count($activeZones) === 0 || !$this->setZoneNumbers($activeZones, false)) {
                $this->safeShutdown($this->Translate('Unable to close the skipped zone'), true);
                return false;
            }
            $this->log(sprintf($this->Translate('Irrigation step %s skipped'), $this->stepName($activeZones)));
            $this->SetBuffer('QueuePosition', (string) ((int) $this->GetBuffer('QueuePosition') + 1));
        } elseif (in_array($phase, ['opening-pump', 'opening-master', 'resuming-pump', 'resuming-master', 'inter-zone'], true)) {
            $queuePosition = $this->nextRunnableQueuePosition();
            $queue = $this->getQueue();
            $step = $queuePosition < 0 ? [] : $this->runnableZonesForStep($queue[$queuePosition] ?? []);
            if ($queuePosition < 0 || count($step) === 0) {
                $this->safeShutdown($this->Translate('Program completed'), false);
                return true;
            }
            $this->log(sprintf($this->Translate('Irrigation step %s skipped before opening'), $this->stepName($step)));
            $this->SetBuffer('QueuePosition', (string) ($queuePosition + 1));
            $preserveDeadline = true;
            if ($phase === 'resuming-pump') {
                $this->SetBuffer('Phase', 'opening-pump');
                $phase = 'opening-pump';
            } elseif ($phase === 'resuming-master') {
                $this->SetBuffer('Phase', 'opening-master');
                $phase = 'opening-master';
            }
            $this->SetBuffer('PausedRemainingSeconds', '0');
            $this->SetBuffer('PausedZoneRemaining', '{}');
            $this->SetBuffer('PauseStartedAt', '0');
        } else {
            $this->setError($this->Translate('Skipping is only possible while preparing, watering or changing zones'));
            return false;
        }

        $this->writeValue('Skip', false);
        $this->writeValue('RemainingSeconds', 0);
        $this->writeValue('CurrentZone', 0);
        $this->clearAllZoneProgress();
        $this->SetBuffer('ActiveStepZones', '[]');
        $this->SetBuffer('ActiveZoneDeadlines', '{}');
        $this->SetBuffer('ActiveZoneTotalSeconds', '{}');
        $this->SetBuffer('CurrentZoneTotalSeconds', '0');
        if ((int) $this->GetBuffer('QueuePosition') >= count($this->getQueue())) {
            $this->safeShutdown($this->Translate('Program completed'), false);
            return true;
        }

        if ($phase === 'inter-zone' || $phase === 'running-zone') {
            $this->SetBuffer('Phase', 'inter-zone');
            if (!$preserveDeadline) {
                $this->SetBuffer('PhaseDeadline', (string) (time() + $this->zoneDelaySeconds()));
            }
            $this->setStateForStep(self::STATE_INTER_ZONE, $this->nextQueuedStep());
        } else {
            $this->setStateForStep(self::STATE_OPENING_MASTER, $this->nextQueuedStep());
        }
        if (!$preserveDeadline) {
            $this->armFeedbackDeadline();
        }
        return true;
    }

    public function SetZoneAutomatic(int $Zone, bool $Automatic): bool
    {
        if ($Zone < 1 || $Zone > self::MAX_ZONES) {
            $this->setError($this->Translate('Invalid zone number'));
            return false;
        }
        $this->writeValue('Zone' . $Zone . 'Automatic', $Automatic);
        return true;
    }

    public function SetZoneRuntime(int $Zone, int $RuntimeMinutes): bool
    {
        if ($Zone < 1 || $Zone > self::MAX_ZONES) {
            $this->setError($this->Translate('Invalid zone number'));
            return false;
        }
        $this->writeValue('Zone' . $Zone . 'Runtime', max(1, min(240, $RuntimeMinutes)));
        return true;
    }

    public function Pause(): bool
    {
        if (!$this->isRunning() || $this->GetBuffer('Phase') !== 'running-zone') {
            $this->setError($this->Translate('Pause is only possible while a zone is watering'));
            return false;
        }

        $activeZones = $this->getActiveStepZones();
        if (count($activeZones) === 0) {
            $this->safeShutdown($this->Translate('Unable to pause an invalid zone'), true);
            return false;
        }

        $deadlines = $this->getIntegerMapBuffer('ActiveZoneDeadlines');
        $remainingByZone = [];
        foreach ($activeZones as $zoneNumber) {
            $remainingByZone[(string) $zoneNumber] = max(1, ($deadlines[(string) $zoneNumber] ?? time() + 1) - time());
        }
        $remaining = count($remainingByZone) > 0 ? max($remainingByZone) : 1;
        $this->SetBuffer('PausedRemainingSeconds', (string) $remaining);
        $this->SetBuffer('PausedZoneRemaining', json_encode($remainingByZone) ?: '{}');
        $this->SetBuffer('PauseStartedAt', (string) time());
        if (!$this->setZoneNumbers($activeZones, false) || !$this->setMainValves(false)) {
            $this->safeShutdown($this->Translate('Unable to close valves for pause'), true);
            return false;
        }

        $this->SetBuffer('Phase', 'paused');
        $this->SetBuffer('PhaseDeadline', '0');
        $this->writeValue('Pause', true);
        $this->writeValue('RemainingSeconds', $remaining);
        foreach ($activeZones as $zoneNumber) {
            $this->updateZoneProgress($zoneNumber, $remainingByZone[(string) $zoneNumber]);
        }
        $this->setStateForStep(self::STATE_PAUSED, $activeZones);
        $this->armFeedbackDeadline();
        $this->log(sprintf($this->Translate('Irrigation step %s paused with %d second(s) remaining'), $this->stepName($activeZones), $remaining));
        return true;
    }

    public function Resume(): bool
    {
        if (!$this->isRunning() || $this->GetBuffer('Phase') !== 'paused') {
            $this->setError($this->Translate('No paused irrigation run is available'));
            return false;
        }
        $this->refreshSensorState();
        $activeZones = $this->getActiveStepZones();
        if ($this->isSoilMoistureBlocked()) {
            $this->setError($this->Translate('Resume blocked by rain or soil moisture sensor'));
            return false;
        }
        if ($this->isRainActive()) {
            $activeZones = $this->runnableZonesForStep($activeZones);
            if (count($activeZones) === 0) {
                $this->setError($this->Translate('Resume blocked by rain or soil moisture sensor'));
                return false;
            }
            $this->SetBuffer('ActiveStepZones', json_encode($activeZones) ?: '[]');
            $this->writeValue('CurrentZone', $activeZones[0]);
        }
        if (!$this->feedbackMatchesExpected()) {
            if (time() >= (int) $this->GetBuffer('FeedbackDeadline')) {
                $this->safeShutdown($this->Translate('Safety stop: paused zone did not close'), true);
            } else {
                $this->setError($this->Translate('Resume waits for closed valve feedback'));
            }
            return false;
        }

        $pauseStartedAt = (int) $this->GetBuffer('PauseStartedAt');
        $programStartedAt = (int) $this->GetBuffer('ProgramStartedAt');
        if ($pauseStartedAt > 0 && $programStartedAt > 0) {
            $this->SetBuffer('ProgramStartedAt', (string) ($programStartedAt + max(0, time() - $pauseStartedAt)));
        }
        $this->writeValue('LastError', '');
        $this->writeValue('Pause', false);
        return $this->beginSupplySequence(true);
    }

    public function TogglePause(): bool
    {
        if ($this->GetBuffer('Phase') === 'paused') {
            return $this->Resume();
        }

        return $this->Pause();
    }

    public function CheckSchedule(): void
    {
        if (!$this->ReadPropertyBoolean('Enabled') || !$this->getValueBoolean('Automatic') || $this->isRunning()) {
            return;
        }

        $now = new DateTimeImmutable('now');
        if (!$this->isScheduledDate($now) || $now->format('H:i') !== $this->normalizedStartTime()) {
            return;
        }

        $date = $now->format('Y-m-d');
        if ($this->GetBuffer('LastScheduleDate') === $date) {
            return;
        }

        // Mark first to prevent a second timer tick from starting the same program.
        $this->SetBuffer('LastScheduleDate', $date);
        $this->StartProgram(false);
    }

    public function Tick(): void
    {
        if ($this->GetBuffer('ShutdownPending') === '1') {
            $this->verifyShutdownFeedback();
            return;
        }
        if (!$this->isRunning()) {
            $this->SetTimerInterval('RunTimer', 0);
            return;
        }

        if (!$this->ReadPropertyBoolean('Enabled')) {
            $this->safeShutdown($this->Translate('Safety stop: module disabled'), true);
            return;
        }

        $this->refreshSensorState();
        if ($this->isSoilMoistureBlocked()) {
            $this->safeShutdown($this->Translate('Safety stop: soil moisture limit reached'), true);
            return;
        }
        if ($this->isRainActive() && $this->skipCurrentZoneDueToRain()) {
            return;
        }

        $phase = $this->GetBuffer('Phase');
        $startedAt = (int) $this->GetBuffer('ProgramStartedAt');
        $maximumSeconds = max(1, $this->ReadPropertyInteger('MaximumProgramMinutes')) * 60;
        if ($phase !== 'paused' && $startedAt > 0 && time() - $startedAt > $maximumSeconds) {
            $this->safeShutdown($this->Translate('Safety stop: maximum program runtime exceeded'), true);
            return;
        }

        $feedbackDeadline = (int) $this->GetBuffer('FeedbackDeadline');
        if ($feedbackDeadline > 0 && time() >= $feedbackDeadline && !$this->feedbackMatchesExpected()) {
            $this->safeShutdown($this->Translate('Safety stop: valve feedback mismatch'), true);
            return;
        }

        $deadline = (int) $this->GetBuffer('PhaseDeadline');
        $now = time();

        if ($phase === 'opening-pump' || $phase === 'resuming-pump') {
            if ($now < $deadline) {
                return;
            }
            if (!$this->feedbackMatchesExpected() && $feedbackDeadline > $now) {
                return;
            }
            $this->continueSupplySequence($phase === 'resuming-pump');
            return;
        }

        if ($phase === 'opening-master') {
            if ($now < $deadline) {
                return;
            }
            if (!$this->feedbackMatchesExpected() && $feedbackDeadline > $now) {
                return;
            }
            $this->startCurrentZone();
            return;
        }

        if ($phase === 'resuming-master') {
            if ($now < $deadline) {
                return;
            }
            if (!$this->feedbackMatchesExpected() && $feedbackDeadline > $now) {
                return;
            }
            $this->resumeCurrentZone();
            return;
        }

        if ($phase === 'paused') {
            return;
        }

        if ($phase === 'running-zone') {
            $this->tickActiveStep($now);
            return;
        }

        if ($phase === 'inter-zone' && $now >= $deadline) {
            $this->advanceQueue();
        }
    }

    private function startQueue(array $queue, string $source, int $runtimeOverrideMinutes): bool
    {
        $queue = $this->normalizeQueue($queue);
        if (!$this->ReadPropertyBoolean('Enabled')) {
            $this->setError($this->Translate('Module is disabled'));
            return false;
        }
        if ($this->isRunning()) {
            $this->setError($this->Translate('An irrigation run is already active'));
            return false;
        }
        if (count($queue) === 0) {
            $this->setError($this->Translate('No enabled zones are configured'));
            return false;
        }

        $this->refreshSensorState();
        if ($this->isSoilMoistureBlocked()) {
            $this->setState(self::STATE_BLOCKED);
            $this->setError($this->Translate('Start blocked by soil moisture sensor'));
            return false;
        }

        if ($this->isRainActive()) {
            $queue = array_values(array_filter(array_map(
                fn (array $step): array => array_values(array_filter(
                    $step,
                    fn (int $zoneNumber): bool => !$this->zoneRespondsToRain($zoneNumber)
                )),
                $queue
            )));
            if (count($queue) === 0) {
                $this->setState(self::STATE_BLOCKED);
                $this->setError($this->Translate('Start blocked: all selected zones react to rain'));
                return false;
            }
        }

        $this->SetBuffer('Queue', json_encode(array_values($queue)) ?: '[]');
        $this->SetBuffer('QueuePosition', '0');
        $this->SetBuffer('RunSource', $source);
        $this->SetBuffer('RuntimeOverrideMinutes', (string) $runtimeOverrideMinutes);
        $this->SetBuffer('ProgramStartedAt', (string) time());
        $this->SetBuffer('RunSimulation', $this->ReadPropertyBoolean('Simulation') ? '1' : '0');
        $this->SetBuffer('RunFeedbackDefinitions', json_encode($this->getOutputDefinitions()) ?: '[]');
        $this->SetBuffer('ActiveStepZones', '[]');
        $this->SetBuffer('ActiveZoneDeadlines', '{}');
        $this->SetBuffer('ActiveZoneTotalSeconds', '{}');
        $this->SetBuffer('StopInProgress', '0');
        $this->writeValue('LastError', '');
        $this->writeValue('ProgramActive', true);
        $this->writeValue('Pause', false);
        $this->writeValue('CurrentZone', 0);
        $this->clearAllZoneProgress();
        $this->setStateForStep(self::STATE_OPENING_MASTER, $this->nextQueuedStep());

        $this->SetTimerInterval('RunTimer', 1000);
        $this->log(sprintf('Irrigation started (%s)', $source));
        return $this->beginSupplySequence(false);
    }

    private function startCurrentZone(): void
    {
        $queue = $this->getQueue();
        $position = (int) $this->GetBuffer('QueuePosition');
        while ($position < count($queue) && count($this->runnableZonesForStep($queue[$position])) === 0) {
            $this->log(sprintf($this->Translate('Irrigation step %s skipped because rain is active'), $this->stepName($queue[$position])));
            $position++;
            $this->SetBuffer('QueuePosition', (string) $position);
        }
        if ($position >= count($queue)) {
            $this->safeShutdown($this->Translate('Program completed; remaining zones skipped because of rain'), false);
            return;
        }
        $step = $this->runnableZonesForStep($queue[$position] ?? []);
        if (count($step) === 0) {
            $this->safeShutdown($this->Translate('Queue contains an invalid zone'), true);
            return;
        }

        if (!$this->setZoneNumbers($step, true)) {
            $this->safeShutdown(sprintf($this->Translate('Unable to open irrigation step %s'), $this->stepName($step)), true);
            return;
        }

        $now = time();
        $deadlines = [];
        $totals = [];
        foreach ($step as $zoneNumber) {
            if ($this->GetBuffer('RunSimulation') === '1') {
                $minutes = max(1, min(240, $this->ReadPropertyInteger('SimulationRuntimeMinutes')));
            } else {
                $override = (int) $this->GetBuffer('RuntimeOverrideMinutes');
                $minutes = $override > 0 ? $override : $this->zoneRuntimeMinutes($zoneNumber);
            }
            $totals[(string) $zoneNumber] = $minutes * 60;
            $deadlines[(string) $zoneNumber] = $now + ($minutes * 60);
        }
        $remaining = count($totals) > 0 ? max($totals) : 0;

        $this->SetBuffer('Phase', 'running-zone');
        $this->SetBuffer('PhaseDeadline', (string) (count($deadlines) > 0 ? max($deadlines) : $now));
        $this->SetBuffer('CurrentZoneTotalSeconds', (string) $remaining);
        $this->SetBuffer('ActiveStepZones', json_encode($step) ?: '[]');
        $this->SetBuffer('ActiveZoneDeadlines', json_encode($deadlines) ?: '{}');
        $this->SetBuffer('ActiveZoneTotalSeconds', json_encode($totals) ?: '{}');
        $this->writeValue('CurrentZone', $step[0]);
        $this->writeValue('RemainingSeconds', $remaining);
        $this->clearAllZoneProgress();
        foreach ($step as $zoneNumber) {
            $this->updateZoneProgress($zoneNumber, $totals[(string) $zoneNumber]);
        }
        $this->setStateForStep(self::STATE_RUNNING, $step);
        $this->armFeedbackDeadline();
        $this->log(sprintf($this->Translate('Irrigation step %s opened'), $this->stepName($step)));
    }

    private function skipCurrentZoneDueToRain(): bool
    {
        if ($this->GetBuffer('Phase') !== 'running-zone') {
            return false;
        }

        $activeZones = $this->getActiveStepZones();
        $affectedZones = array_values(array_filter(
            $activeZones,
            fn (int $zoneNumber): bool => $this->zoneRespondsToRain($zoneNumber)
        ));
        if (count($affectedZones) === 0) {
            return false;
        }

        if (!$this->setZoneNumbers($affectedZones, false)) {
            $this->safeShutdown($this->Translate('Unable to close rain-sensitive zone'), true);
            return true;
        }

        foreach ($affectedZones as $zoneNumber) {
            $this->writeValue('Zone' . $zoneNumber . 'Progress', '');
        }
        $remainingZones = array_values(array_diff($activeZones, $affectedZones));
        $this->SetBuffer('ActiveStepZones', json_encode($remainingZones) ?: '[]');
        $this->removeZonesFromActiveTiming($affectedZones);
        $this->log(sprintf($this->Translate('Irrigation step %s partially or completely stopped because rain is active'), $this->stepName($affectedZones)));
        if (count($remainingZones) > 0) {
            $deadlines = $this->getIntegerMapBuffer('ActiveZoneDeadlines');
            $remaining = 0;
            foreach ($remainingZones as $zoneNumber) {
                $remaining = max($remaining, max(0, ($deadlines[(string) $zoneNumber] ?? time()) - time()));
            }
            $this->writeValue('CurrentZone', $remainingZones[0]);
            $this->writeValue('RemainingSeconds', $remaining);
            $this->SetBuffer('PhaseDeadline', (string) (time() + $remaining));
            $this->setStateForStep(self::STATE_RUNNING, $remainingZones);
            $this->armFeedbackDeadline();
            return true;
        }

        $position = (int) $this->GetBuffer('QueuePosition') + 1;
        $this->SetBuffer('QueuePosition', (string) $position);
        $this->writeValue('RemainingSeconds', 0);
        $this->writeValue('CurrentZone', 0);
        $this->clearAllZoneProgress();
        $this->SetBuffer('CurrentZoneTotalSeconds', '0');

        if ($position >= count($this->getQueue())) {
            $this->safeShutdown($this->Translate('Program completed; remaining zones skipped because of rain'), false);
            return true;
        }

        $this->SetBuffer('Phase', 'inter-zone');
        $this->SetBuffer('PhaseDeadline', (string) (time() + $this->zoneDelaySeconds()));
        $this->setStateForStep(self::STATE_INTER_ZONE, $this->nextQueuedStep());
        $this->armFeedbackDeadline();
        return true;
    }

    private function resumeCurrentZone(): void
    {
        $activeZones = $this->getActiveStepZones();
        if (count($activeZones) === 0) {
            $this->safeShutdown($this->Translate('Unable to resume an invalid zone'), true);
            return;
        }
        if (!$this->setZoneNumbers($activeZones, true)) {
            $this->safeShutdown(sprintf($this->Translate('Unable to reopen irrigation step %s'), $this->stepName($activeZones)), true);
            return;
        }

        $pausedRemaining = $this->getIntegerMapBuffer('PausedZoneRemaining');
        $deadlines = [];
        $totals = $this->getIntegerMapBuffer('ActiveZoneTotalSeconds');
        $maximumRemaining = 0;
        foreach ($activeZones as $zoneNumber) {
            $remaining = max(1, $pausedRemaining[(string) $zoneNumber] ?? (int) $this->GetBuffer('PausedRemainingSeconds'));
            $deadlines[(string) $zoneNumber] = time() + $remaining;
            $totals[(string) $zoneNumber] = max($remaining, $totals[(string) $zoneNumber] ?? $remaining);
            $maximumRemaining = max($maximumRemaining, $remaining);
        }
        $remaining = max(1, $maximumRemaining);
        $this->SetBuffer('Phase', 'running-zone');
        $this->SetBuffer('PhaseDeadline', (string) (count($deadlines) > 0 ? max($deadlines) : time() + $remaining));
        $this->SetBuffer('ActiveZoneDeadlines', json_encode($deadlines) ?: '{}');
        $this->SetBuffer('ActiveZoneTotalSeconds', json_encode($totals) ?: '{}');
        $this->SetBuffer('PauseStartedAt', '0');
        $this->writeValue('RemainingSeconds', $remaining);
        foreach ($activeZones as $zoneNumber) {
            $this->updateZoneProgress($zoneNumber, $pausedRemaining[(string) $zoneNumber] ?? $remaining);
        }
        $this->setStateForStep(self::STATE_RUNNING, $activeZones);
        $this->armFeedbackDeadline();
        $this->log(sprintf($this->Translate('Irrigation step %s resumed with %d second(s) remaining'), $this->stepName($activeZones), $remaining));
    }

    private function finishCurrentZone(): void
    {
        $activeZones = $this->getActiveStepZones();
        $this->setZoneNumbers($activeZones, false);

        $this->writeValue('RemainingSeconds', 0);
        $this->clearAllZoneProgress();
        $this->SetBuffer('CurrentZoneTotalSeconds', '0');
        $this->SetBuffer('ActiveStepZones', '[]');
        $this->SetBuffer('ActiveZoneDeadlines', '{}');
        $this->SetBuffer('ActiveZoneTotalSeconds', '{}');
        $this->log(sprintf($this->Translate('Irrigation step %s closed'), $this->stepName($activeZones)));
        $position = (int) $this->GetBuffer('QueuePosition') + 1;
        $this->SetBuffer('QueuePosition', (string) $position);

        if ($position >= count($this->getQueue())) {
            $this->safeShutdown($this->Translate('Program completed'), false);
            return;
        }

        $this->SetBuffer('Phase', 'inter-zone');
        $this->SetBuffer('PhaseDeadline', (string) (time() + $this->zoneDelaySeconds()));
        $this->writeValue('CurrentZone', 0);
        $this->setStateForStep(self::STATE_INTER_ZONE, $this->nextQueuedStep());
        $this->armFeedbackDeadline();
    }

    private function advanceQueue(): void
    {
        if (!$this->feedbackMatchesExpected()) {
            $feedbackDeadline = (int) $this->GetBuffer('FeedbackDeadline');
            if ($feedbackDeadline > time()) {
                return;
            }
            $this->safeShutdown($this->Translate('Safety stop: previous zone did not close'), true);
            return;
        }
        $this->startCurrentZone();
    }

    private function beginSupplySequence(bool $resuming): bool
    {
        if (!$this->setMainValveSlot(0, true)) {
            $this->safeShutdown($this->Translate('Unable to start the irrigation pump'), true);
            return false;
        }

        $wait = $this->mainValveSlotHasOutput(0) ? $this->pumpDelaySeconds() : 0;
        $this->setStateForStep(self::STATE_OPENING_MASTER, $resuming ? $this->getActiveStepZones() : $this->nextQueuedStep());
        $this->SetBuffer('Phase', $resuming ? 'resuming-pump' : 'opening-pump');
        $this->SetBuffer('PhaseDeadline', (string) (time() + $wait));
        $this->armFeedbackDeadline();
        if ($wait === 0 && $this->feedbackMatchesExpected()) {
            return $this->continueSupplySequence($resuming);
        }
        return true;
    }

    private function continueSupplySequence(bool $resuming): bool
    {
        if (!$this->setMainValveSlot(1, true)) {
            $this->safeShutdown($this->Translate('Unable to open the main valve'), true);
            return false;
        }

        $wait = $this->mainValveSlotHasOutput(1) ? $this->zoneDelaySeconds() : 0;
        $this->SetBuffer('Phase', $resuming ? 'resuming-master' : 'opening-master');
        $this->SetBuffer('PhaseDeadline', (string) (time() + $wait));
        $this->armFeedbackDeadline();
        if ($wait === 0 && $this->feedbackMatchesExpected()) {
            if ($resuming) {
                $this->resumeCurrentZone();
            } else {
                $this->startCurrentZone();
            }
        }
        return true;
    }

    private function safeShutdown(string $reason, bool $isError): void
    {
        if ($this->GetBuffer('StopInProgress') === '1') {
            return;
        }
        $this->SetBuffer('StopInProgress', '1');
        $shutdownSimulation = $this->GetBuffer('ShutdownPending') === '1'
            ? $this->GetBuffer('ShutdownSimulation') === '1'
            : ($this->isRunning() ? $this->GetBuffer('RunSimulation') === '1' : $this->ReadPropertyBoolean('Simulation'));
        $this->SetBuffer('ShutdownSimulation', $shutdownSimulation ? '1' : '0');
        $failures = [];

        // Every configured zone is closed, not only the remembered current zone.
        $closedIDs = [];
        foreach ($this->getZones() as $zone) {
            if (!is_array($zone)) {
                continue;
            }
            foreach ($this->getValveIDs($zone) as $id) {
                $closedIDs[$id] = true;
                if (!$this->switchOutput($id, false)) {
                    $failures[] = (string) $id;
                }
            }
        }
        foreach ($this->getMainValves() as $mainValve) {
            if (!is_array($mainValve) || !($mainValve['Enabled'] ?? false)) {
                continue;
            }
            $id = (int) ($mainValve['VariableID'] ?? 0);
            if ($id > 0) {
                $closedIDs[$id] = true;
                if (!$this->switchOutput($id, false)) {
                    $failures[] = (string) $id;
                }
            }
        }
        // Includes outputs from the previous configuration after ApplyChanges().
        foreach (array_keys($this->getExpectedOutputs()) as $expectedID) {
            $id = (int) $expectedID;
            if ($id > 0 && !isset($closedIDs[$id]) && !$this->switchOutput($id, false)) {
                $failures[] = (string) $id;
            }
        }

        $this->SetBuffer('Queue', '[]');
        $this->SetBuffer('QueuePosition', '0');
        $this->SetBuffer('Phase', 'idle');
        $this->SetBuffer('PhaseDeadline', '0');
        $this->SetBuffer('FeedbackDeadline', '0');
        $this->SetBuffer('ProgramStartedAt', '0');
        $this->writeValue('ProgramActive', false);
        $this->writeValue('Pause', false);
        $this->writeValue('Skip', false);
        $this->writeValue('ManualZone', 0);
        $this->writeValue('CurrentZone', 0);
        $this->writeValue('RemainingSeconds', 0);
        $this->clearAllZoneProgress();
        $this->SetBuffer('CurrentZoneTotalSeconds', '0');
        $this->SetBuffer('ActiveStepZones', '[]');
        $this->SetBuffer('ActiveZoneDeadlines', '{}');
        $this->SetBuffer('ActiveZoneTotalSeconds', '{}');
        $this->SetBuffer('PausedRemainingSeconds', '0');
        $this->SetBuffer('PausedZoneRemaining', '{}');
        $this->SetBuffer('PauseStartedAt', '0');
        $finalState = ($isError || count($failures) > 0) ? self::STATE_ERROR : self::STATE_IDLE;
        $this->SetBuffer('ShutdownFinalState', (string) $finalState);
        $this->SetBuffer('ShutdownReason', $reason);

        if ($isError || count($failures) > 0) {
            $message = $reason;
            if (count($failures) > 0) {
                $message .= '; ' . $this->Translate('failed to close variable(s)') . ': ' . implode(', ', array_unique($failures));
            }
            $this->writeValue('LastError', $message);
        }
        $this->SetBuffer('ShutdownPending', '1');
        $this->armFeedbackDeadline();
        if (!$shutdownSimulation && $this->hasFeedbackDefinitions() && !$this->feedbackMatchesExpected()) {
            $this->setState(self::STATE_STOPPING);
            $this->SetTimerInterval('RunTimer', 1000);
        } else {
            $this->completeShutdown($finalState);
        }
        $this->refreshOutputState();
        $this->SetBuffer('StopInProgress', '0');
        $this->log($reason);
    }

    private function setMainValves(bool $state): bool
    {
        $success = true;
        foreach ($this->getMainValves() as $valve) {
            if (!is_array($valve) || !($valve['Enabled'] ?? false)) {
                continue;
            }
            $id = (int) ($valve['VariableID'] ?? 0);
            if ($id > 0) {
                $success = $this->switchOutput($id, $state) && $success;
            }
        }
        return $success;
    }

    private function setMainValveSlot(int $slot, bool $state): bool
    {
        $valve = $this->getMainValves()[$slot] ?? null;
        if (!is_array($valve) || !($valve['Enabled'] ?? false)) {
            return true;
        }
        $id = (int) ($valve['VariableID'] ?? 0);
        return $id <= 0 || $this->switchOutput($id, $state);
    }

    private function mainValveSlotHasOutput(int $slot): bool
    {
        $valve = $this->getMainValves()[$slot] ?? null;
        return is_array($valve) && ($valve['Enabled'] ?? false) && (int) ($valve['VariableID'] ?? 0) > 0;
    }

    private function setZoneValves(array $zone, bool $state): bool
    {
        $success = true;
        foreach ($this->getValveIDs($zone) as $id) {
            $success = $this->switchOutput($id, $state) && $success;
        }
        return $success;
    }

    private function setZoneNumbers(array $zoneNumbers, bool $state): bool
    {
        $success = true;
        $zones = $this->getZones();
        foreach ($zoneNumbers as $zoneNumber) {
            $zone = $zones[(int) $zoneNumber - 1] ?? null;
            if (!is_array($zone)) {
                $success = false;
                continue;
            }
            $success = $this->setZoneValves($zone, $state) && $success;
        }
        return $success;
    }

    private function switchOutput(int $variableID, bool $state): bool
    {
        if ($variableID <= 0) {
            return true;
        }

        $expected = $this->getExpectedOutputs();
        $expected[(string) $variableID] = $state;
        $this->SetBuffer('ExpectedOutputs', json_encode($expected) ?: '{}');

        $simulation = $this->isRunning()
            ? $this->GetBuffer('RunSimulation') === '1'
            : $this->ReadPropertyBoolean('Simulation');
        if ($simulation) {
            $simulated = $this->getSimulatedOutputs();
            $simulated[(string) $variableID] = $state;
            $this->SetBuffer('SimulatedOutputs', json_encode($simulated) ?: '{}');
            $this->refreshOutputState();
            return true;
        }

        try {
            // This is intentionally the only hardware write in the module.
            RequestAction($variableID, $state);
            $this->refreshOutputState();
            return true;
        } catch (Throwable $exception) {
            $this->SendDebug('RequestAction', sprintf('Variable %d: %s', $variableID, $exception->getMessage()), 0);
            return false;
        }
    }

    private function feedbackMatchesExpected(): bool
    {
        if ($this->isSimulationContext()) {
            return true;
        }

        $expected = $this->getExpectedOutputs();
        foreach ($this->getFeedbackDefinitions() as $definition) {
            $outputID = (int) ($definition['VariableID'] ?? 0);
            $feedbackID = (int) ($definition['FeedbackID'] ?? 0);
            if ($outputID <= 0 || $feedbackID <= 0 || !array_key_exists((string) $outputID, $expected)) {
                continue;
            }
            $expectedState = (bool) $expected[(string) $outputID];
            if (!$expectedState && !$this->ReadPropertyBoolean('MonitorClosedFeedback')) {
                continue;
            }
            $actual = (bool) GetValue($feedbackID);
            if ((bool) ($definition['FeedbackInverted'] ?? false)) {
                $actual = !$actual;
            }
            if ($actual !== $expectedState) {
                return false;
            }
        }
        return true;
    }

    private function verifyShutdownFeedback(): void
    {
        $finalState = (int) $this->GetBuffer('ShutdownFinalState');
        if ($this->feedbackMatchesExpected()) {
            $this->completeShutdown($finalState);
            return;
        }
        if (time() < (int) $this->GetBuffer('FeedbackDeadline')) {
            return;
        }

        $message = $this->GetBuffer('ShutdownReason') . '; ' . $this->Translate('one or more valves did not confirm the closed state');
        $this->writeValue('LastError', $message);
        $this->completeShutdown(self::STATE_ERROR);
    }

    private function completeShutdown(int $finalState): void
    {
        $this->SetBuffer('ShutdownPending', '0');
        $this->SetBuffer('FeedbackDeadline', '0');
        $this->SetBuffer('RunFeedbackDefinitions', '[]');
        $this->SetTimerInterval('RunTimer', 0);
        $this->setState($finalState);
    }

    private function armFeedbackDeadline(): void
    {
        $this->SetBuffer(
            'FeedbackDeadline',
            (string) (time() + max(1, $this->ReadPropertyInteger('FeedbackTimeoutSeconds')))
        );
    }

    private function isSimulationContext(): bool
    {
        if ($this->GetBuffer('ShutdownPending') === '1') {
            return $this->GetBuffer('ShutdownSimulation') === '1';
        }
        if ($this->isRunning()) {
            return $this->GetBuffer('RunSimulation') === '1';
        }
        return $this->ReadPropertyBoolean('Simulation');
    }

    private function getFeedbackDefinitions(): array
    {
        $definitions = $this->getOutputDefinitions();
        $runDefinitions = json_decode($this->GetBuffer('RunFeedbackDefinitions'), true);
        if (is_array($runDefinitions)) {
            $definitions = array_merge($definitions, $runDefinitions);
        }

        $unique = [];
        foreach ($definitions as $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $key = (int) ($definition['VariableID'] ?? 0) . ':' . (int) ($definition['FeedbackID'] ?? 0);
            $unique[$key] = $definition;
        }
        return array_values($unique);
    }

    private function hasFeedbackDefinitions(): bool
    {
        foreach ($this->getFeedbackDefinitions() as $definition) {
            if ((int) ($definition['FeedbackID'] ?? 0) > 0) {
                return true;
            }
        }
        return false;
    }

    private function refreshSensorState(): void
    {
        $blocked = $this->isBlockedBySensors();
        $this->writeValue('SensorBlocked', $blocked);
        if (!$this->isRunning() && $this->getValueInteger('State') === self::STATE_BLOCKED && !$blocked) {
            $this->setState(self::STATE_IDLE);
        }
    }

    private function isBlockedBySensors(): bool
    {
        return $this->isRainActive() || $this->isSoilMoistureBlocked();
    }

    private function isRainActive(): bool
    {
        $rainID = $this->ReadPropertyInteger('RainSensorID');
        if ($rainID > 0 && IPS_VariableExists($rainID)) {
            if ((bool) GetValue($rainID) === $this->ReadPropertyBoolean('RainActiveValue')) {
                return true;
            }
        }
        return false;
    }

    private function isSoilMoistureBlocked(): bool
    {
        $soilID = $this->ReadPropertyInteger('SoilMoistureSensorID');
        if ($soilID > 0 && IPS_VariableExists($soilID)) {
            $value = (float) GetValue($soilID);
            $limit = $this->ReadPropertyFloat('SoilMoistureLimit');
            if ($this->ReadPropertyBoolean('SoilBlocksAboveLimit') ? $value >= $limit : $value <= $limit) {
                return true;
            }
        }
        return false;
    }

    private function isScheduledDate(DateTimeImmutable $date): bool
    {
        $days = [
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
            7 => 'Sunday'
        ];
        if (!$this->ReadPropertyBoolean($days[(int) $date->format('N')])) {
            return false;
        }

        $anchor = $this->parseExactDate('Y-m-d', $this->ReadPropertyString('IntervalAnchor'));
        if (!$anchor) {
            return false;
        }
        $today = $this->parseExactDate('Y-m-d', $date->format('Y-m-d'));
        if (!$today) {
            return false;
        }
        $difference = (int) $anchor->diff($today)->format('%r%a');
        $interval = max(1, $this->ReadPropertyInteger('IntervalDays'));
        if ($difference < 0) {
            return false;
        }

        // An interval occurrence on a disabled weekday is assigned to the next
        // enabled weekday. If several occurrences accumulate, only one run is
        // scheduled on that day.
        $previousEnabledDate = $today->modify('-1 day');
        while ($previousEnabledDate >= $anchor && !$this->ReadPropertyBoolean($days[(int) $previousEnabledDate->format('N')])) {
            $previousEnabledDate = $previousEnabledDate->modify('-1 day');
        }
        $firstUnassignedDay = $previousEnabledDate < $anchor
            ? $anchor
            : $previousEnabledDate->modify('+1 day');
        $firstDifference = max(0, (int) $anchor->diff($firstUnassignedDay)->format('%r%a'));
        $remainder = $firstDifference % $interval;
        $nextOccurrenceDifference = $remainder === 0
            ? $firstDifference
            : $firstDifference + ($interval - $remainder);

        return $nextOccurrenceDifference <= $difference;
    }

    private function normalizedStartTime(): string
    {
        $value = trim($this->ReadPropertyString('StartTime'));
        $date = $this->parseExactDate('H:i', $value);
        return $date ? $date->format('H:i') : '00:00';
    }

    private function validateConfiguration(): array
    {
        $errors = [];
        $rawZones = $this->decodeListProperty('Zones');
        if (count($rawZones) > self::MAX_ZONES) {
            $errors[] = $this->Translate('A maximum of ten zones is supported');
        }
        if (count($this->getMainValves()) > 2) {
            $errors[] = $this->Translate('A maximum of two main valves is supported');
        }
        foreach (['MainValves', 'Zones'] as $property) {
            json_decode($this->ReadPropertyString($property), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors[] = sprintf($this->Translate('Property %s does not contain valid JSON'), $property);
            }
        }

        $seenOutputs = [];
        $seenFeedback = [];
        foreach ($this->getOutputDefinitions() as $definition) {
            $id = (int) ($definition['VariableID'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if (!$this->isBooleanVariable($id)) {
                $errors[] = sprintf($this->Translate('Output variable %d must be Boolean'), $id);
            }
            if (isset($seenOutputs[$id])) {
                $errors[] = sprintf($this->Translate('Output variable %d is configured more than once'), $id);
            }
            $seenOutputs[$id] = true;

            $feedbackID = (int) ($definition['FeedbackID'] ?? 0);
            if ($feedbackID > 0 && !$this->isBooleanVariable($feedbackID)) {
                $errors[] = sprintf($this->Translate('Feedback variable %d must be Boolean'), $feedbackID);
            }
            if ($feedbackID > 0 && isset($seenFeedback[$feedbackID])) {
                $errors[] = sprintf($this->Translate('Feedback variable %d is configured more than once'), $feedbackID);
            }
            if ($feedbackID > 0) {
                $seenFeedback[$feedbackID] = true;
            }
        }
        foreach (array_keys($seenFeedback) as $feedbackID) {
            if (isset($seenOutputs[$feedbackID])) {
                $errors[] = sprintf($this->Translate('Feedback variable %d must not also be an output'), $feedbackID);
            }
        }

        $rainID = $this->ReadPropertyInteger('RainSensorID');
        if ($rainID > 0 && !$this->isBooleanVariable($rainID)) {
            $errors[] = $this->Translate('Rain sensor must be a Boolean variable');
        }
        if ($rainID > 0 && isset($seenOutputs[$rainID])) {
            $errors[] = $this->Translate('Rain sensor must not also be a valve output');
        }
        $soilID = $this->ReadPropertyInteger('SoilMoistureSensorID');
        if ($soilID > 0 && (!$this->variableExists($soilID) || !in_array((int) IPS_GetVariable($soilID)['VariableType'], [1, 2], true))) {
            $errors[] = $this->Translate('Soil moisture sensor must be an Integer or Float variable');
        }
        if (!$this->parseExactDate('H:i', trim($this->ReadPropertyString('StartTime')))) {
            $errors[] = $this->Translate('Start time must use HH:MM');
        }
        if (!$this->parseExactDate('Y-m-d', trim($this->ReadPropertyString('IntervalAnchor')))) {
            $errors[] = $this->Translate('Interval anchor must use YYYY-MM-DD');
        }
        return array_values(array_unique($errors));
    }

    private function configurationWarnings(): array
    {
        $warnings = [];
        foreach ($this->decodeListProperty('Zones') as $index => $zone) {
            if (!is_array($zone)) {
                continue;
            }
            $group = (int) ($zone['Group'] ?? $index + 1);
            if ($group > 100) {
                $warnings[] = sprintf(
                    $this->Translate('Zone %s: group %d exceeds 100 and is limited to 100'),
                    $this->zoneName($index + 1),
                    $group
                );
            } elseif ($group < 1) {
                $warnings[] = sprintf(
                    $this->Translate('Zone %s: group %d is below 1 and is limited to 1'),
                    $this->zoneName($index + 1),
                    $group
                );
            }
        }
        return $warnings;
    }

    private function subscribeVariables(): void
    {
        $ids = [];
        foreach ([$this->ReadPropertyInteger('RainSensorID'), $this->ReadPropertyInteger('SoilMoistureSensorID')] as $id) {
            if ($id > 0 && $this->variableExists($id)) {
                $ids[$id] = true;
            }
        }
        foreach ($this->getOutputDefinitions() as $definition) {
            $id = (int) ($definition['FeedbackID'] ?? 0);
            if ($id > 0 && $this->variableExists($id)) {
                $ids[$id] = true;
            }
        }
        foreach (array_keys($ids) as $id) {
            $this->RegisterMessage((int) $id, self::MESSAGE_VARIABLE_UPDATE);
        }
        $this->SetBuffer('Subscriptions', json_encode(array_map('intval', array_keys($ids))) ?: '[]');
    }

    private function parseExactDate(string $format, string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        if (!$date || $date->format($format) !== $value) {
            return null;
        }
        return $date;
    }

    private function unsubscribeVariables(): void
    {
        $ids = json_decode($this->GetBuffer('Subscriptions'), true);
        if (!is_array($ids)) {
            return;
        }
        foreach ($ids as $id) {
            if ((int) $id > 0) {
                $this->UnregisterMessage((int) $id, self::MESSAGE_VARIABLE_UPDATE);
            }
        }
        $this->SetBuffer('Subscriptions', '[]');
    }

    private function getMainValves(): array
    {
        return $this->decodeListProperty('MainValves');
    }

    private function getZones(): array
    {
        $zones = array_slice($this->decodeListProperty('Zones'), 0, self::MAX_ZONES);
        foreach ($zones as $index => &$zone) {
            if (is_array($zone)) {
                $zone['Group'] = max(1, min(100, (int) ($zone['Group'] ?? $index + 1)));
            }
        }
        unset($zone);
        return $zones;
    }

    private function decodeListProperty(string $name): array
    {
        $value = json_decode($this->ReadPropertyString($name), true);
        return is_array($value) ? array_values($value) : [];
    }

    private function getValveIDs(array $zone): array
    {
        $ids = [];
        foreach (['Valve1ID', 'Valve2ID'] as $key) {
            $id = (int) ($zone[$key] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        return array_map('intval', array_keys($ids));
    }

    private function zoneHasOutput(array $zone): bool
    {
        return count($this->getValveIDs($zone)) > 0;
    }

    private function zoneRespondsToRain(int $zoneNumber): bool
    {
        $zone = $this->getZones()[$zoneNumber - 1] ?? null;
        return !is_array($zone) || (bool) ($zone['RainSensitive'] ?? true);
    }

    private function zoneGroup(int $zoneNumber): int
    {
        $zone = $this->getZones()[$zoneNumber - 1] ?? null;
        return max(1, min(100, (int) (is_array($zone) ? ($zone['Group'] ?? $zoneNumber) : $zoneNumber)));
    }

    private function getOutputDefinitions(): array
    {
        $definitions = [];
        foreach ($this->getMainValves() as $valve) {
            if (is_array($valve) && ($valve['Enabled'] ?? false)) {
                $valve['Label'] = trim((string) ($valve['Name'] ?? '')) ?: $this->Translate('Main valve');
                $definitions[] = $valve;
            }
        }
        foreach ($this->getZones() as $index => $zone) {
            if (!is_array($zone)) {
                continue;
            }
            $zoneName = trim((string) ($zone['Name'] ?? '')) ?: sprintf($this->Translate('Zone %d'), $index + 1);
            $hasTwoValves = count($this->getValveIDs($zone)) > 1;
            for ($number = 1; $number <= 2; $number++) {
                $definitions[] = [
                    'VariableID' => (int) ($zone['Valve' . $number . 'ID'] ?? 0),
                    'FeedbackID' => (int) ($zone['Feedback' . $number . 'ID'] ?? 0),
                    'FeedbackInverted' => (bool) ($zone['Feedback' . $number . 'Inverted'] ?? false),
                    'Label' => $hasTwoValves ? $zoneName . ' / ' . sprintf($this->Translate('Valve %d'), $number) : $zoneName
                ];
            }
        }
        return $definitions;
    }

    private function getQueue(): array
    {
        $queue = json_decode($this->GetBuffer('Queue'), true);
        return is_array($queue) ? $this->normalizeQueue($queue) : [];
    }

    private function normalizeQueue(array $queue): array
    {
        $normalized = [];
        foreach ($queue as $step) {
            $zones = is_array($step) ? $step : [$step];
            $zones = array_values(array_unique(array_filter(
                array_map('intval', $zones),
                static fn (int $zoneNumber): bool => $zoneNumber >= 1 && $zoneNumber <= self::MAX_ZONES
            )));
            if (count($zones) > 0) {
                $normalized[] = $zones;
            }
        }
        return $normalized;
    }

    private function nextRunnableQueuePosition(): int
    {
        $queue = $this->getQueue();
        $position = max(0, (int) $this->GetBuffer('QueuePosition'));
        while ($position < count($queue) && count($this->runnableZonesForStep($queue[$position])) === 0) {
            $position++;
        }
        return $position < count($queue) ? $position : -1;
    }

    private function nextQueuedStep(): array
    {
        $position = $this->nextRunnableQueuePosition();
        return $position < 0 ? [] : $this->runnableZonesForStep($this->getQueue()[$position] ?? []);
    }

    private function runnableZonesForStep(array $step): array
    {
        if (!$this->isRainActive()) {
            return $step;
        }
        return array_values(array_filter(
            $step,
            fn (int $zoneNumber): bool => !$this->zoneRespondsToRain($zoneNumber)
        ));
    }

    private function getActiveStepZones(): array
    {
        $zones = json_decode($this->GetBuffer('ActiveStepZones'), true);
        return is_array($zones) ? array_values(array_map('intval', $zones)) : [];
    }

    private function getIntegerMapBuffer(string $name): array
    {
        $values = json_decode($this->GetBuffer($name), true);
        if (!is_array($values)) {
            return [];
        }
        foreach ($values as $key => $value) {
            $values[(string) $key] = (int) $value;
        }
        return $values;
    }

    private function removeZonesFromActiveTiming(array $zoneNumbers): void
    {
        foreach (['ActiveZoneDeadlines', 'ActiveZoneTotalSeconds', 'PausedZoneRemaining'] as $buffer) {
            $values = $this->getIntegerMapBuffer($buffer);
            foreach ($zoneNumbers as $zoneNumber) {
                unset($values[(string) $zoneNumber]);
            }
            $this->SetBuffer($buffer, json_encode($values) ?: '{}');
        }
    }

    private function tickActiveStep(int $now): void
    {
        $activeZones = $this->getActiveStepZones();
        $deadlines = $this->getIntegerMapBuffer('ActiveZoneDeadlines');
        $stillActive = [];
        $maximumRemaining = 0;
        $closedZone = false;
        foreach ($activeZones as $zoneNumber) {
            $remaining = max(0, ($deadlines[(string) $zoneNumber] ?? $now) - $now);
            if ($remaining === 0) {
                $zone = $this->getZones()[$zoneNumber - 1] ?? null;
                if (!is_array($zone) || !$this->setZoneValves($zone, false)) {
                    $this->safeShutdown($this->Translate('Unable to close an expired group zone'), true);
                    return;
                }
                $this->writeValue('Zone' . $zoneNumber . 'Progress', '');
                $closedZone = true;
                continue;
            }
            $stillActive[] = $zoneNumber;
            $maximumRemaining = max($maximumRemaining, $remaining);
            $this->updateZoneProgress($zoneNumber, $remaining);
        }

        if (count($stillActive) === 0) {
            $this->finishCurrentZone();
            return;
        }

        if ($stillActive !== $activeZones) {
            $this->SetBuffer('ActiveStepZones', json_encode($stillActive) ?: '[]');
            $this->writeValue('CurrentZone', $stillActive[0]);
            $this->setStateForStep(self::STATE_RUNNING, $stillActive);
        }
        if ($closedZone) {
            $this->armFeedbackDeadline();
        }
        $this->writeValue('RemainingSeconds', $maximumRemaining);
        $this->SetBuffer('PhaseDeadline', (string) ($now + $maximumRemaining));
    }

    private function getExpectedOutputs(): array
    {
        $values = json_decode($this->GetBuffer('ExpectedOutputs'), true);
        return is_array($values) ? $values : [];
    }

    private function getSimulatedOutputs(): array
    {
        $values = json_decode($this->GetBuffer('SimulatedOutputs'), true);
        return is_array($values) ? $values : [];
    }

    private function refreshOutputState(): void
    {
        $simulation = $this->isSimulationContext();
        $states = $simulation ? $this->getSimulatedOutputs() : $this->getExpectedOutputs();
        $labels = [];
        $order = [];
        foreach ($this->getFeedbackDefinitions() as $definition) {
            $id = (int) ($definition['VariableID'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $labels[(string) $id] = trim((string) ($definition['Label'] ?? '')) ?: sprintf($this->Translate('Variable %d'), $id);
            $order[(string) $id] = count($order);
        }
        uksort($states, static function ($left, $right) use ($order): int {
            return ($order[(string) $left] ?? PHP_INT_MAX) <=> ($order[(string) $right] ?? PHP_INT_MAX);
        });
        $parts = [];
        foreach ($states as $id => $state) {
            $label = $labels[(string) $id] ?? sprintf($this->Translate('Variable %d'), (int) $id);
            $parts[] = $label . '=' . ((bool) $state ? $this->Translate('ON') : $this->Translate('OFF'));
        }
        $prefix = $simulation ? 'SIM: ' : '';
        $this->writeValue('OutputState', $prefix . implode(', ', $parts));
    }

    private function zoneName(int $zoneNumber): string
    {
        $zone = $this->getZones()[$zoneNumber - 1] ?? null;
        if (is_array($zone)) {
            $name = trim((string) ($zone['Name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
        return sprintf($this->Translate('Zone %d'), $zoneNumber);
    }

    private function stepName(array $zoneNumbers): string
    {
        $zoneNumbers = array_values(array_map('intval', $zoneNumbers));
        if (count($zoneNumbers) === 0) {
            return $this->Translate('No zone');
        }
        $names = array_map(fn (int $zoneNumber): string => $this->zoneName($zoneNumber), $zoneNumbers);
        $groupRun = $this->getValueInteger('IrrigationMode') === 2 && $this->GetBuffer('RunSource') !== 'manual-zone';
        if (count($zoneNumbers) === 1 && !$groupRun) {
            return $names[0];
        }
        return sprintf(
            $this->Translate('Group %d: %s'),
            $this->zoneGroup($zoneNumbers[0]),
            implode(', ', $names)
        );
    }

    private function updateZoneProgress(int $zoneNumber, int $remainingSeconds): void
    {
        if ($zoneNumber < 1 || $zoneNumber > self::MAX_ZONES) {
            return;
        }
        $remainingSeconds = max(0, $remainingSeconds);
        $totals = $this->getIntegerMapBuffer('ActiveZoneTotalSeconds');
        $totalSeconds = max(
            $remainingSeconds,
            $totals[(string) $zoneNumber] ?? (int) $this->GetBuffer('CurrentZoneTotalSeconds')
        );
        $elapsedSeconds = max(0, $totalSeconds - $remainingSeconds);
        $this->writeValue(
            'Zone' . $zoneNumber . 'Progress',
            sprintf(
                $this->Translate('since %s, %s remaining'),
                $this->formatProgressDuration($elapsedSeconds),
                $this->formatProgressDuration($remainingSeconds)
            )
        );
    }

    private function clearAllZoneProgress(): void
    {
        for ($zone = 1; $zone <= self::MAX_ZONES; $zone++) {
            $this->writeValue('Zone' . $zone . 'Progress', '');
        }
    }

    private function formatProgressDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return sprintf($this->Translate('%d sec'), $seconds);
        }
        return sprintf($this->Translate('%d min'), intdiv($seconds, 60));
    }

    private function registerZoneControls(): void
    {
        for ($zone = 1; $zone <= self::MAX_ZONES; $zone++) {
            $position = 300 + (($zone - 1) * 30);
            $name = $this->zoneName($zone);
            $this->RegisterVariableBoolean(
                'Zone' . $zone . 'Automatic',
                sprintf($this->Translate('%s: automatic program'), $name),
                $this->switchPresentation(),
                $position
            );
            $this->EnableAction('Zone' . $zone . 'Automatic');
            $this->RegisterVariableInteger(
                'Zone' . $zone . 'Runtime',
                sprintf($this->Translate('%s: runtime'), $name),
                $this->minutesPresentation(),
                $position + 10
            );
            $this->EnableAction('Zone' . $zone . 'Runtime');
            $this->RegisterVariableString(
                'Zone' . $zone . 'Progress',
                $name,
                $this->valuePresentation(),
                $position + 20
            );
        }
    }

    private function initializeZoneControlsFromConfiguration(): void
    {
        $zones = $this->getZones();
        for ($zone = 1; $zone <= self::MAX_ZONES; $zone++) {
            $configuration = $zones[$zone - 1] ?? $this->defaultZone($zone);
            $this->writeValue('Zone' . $zone . 'Automatic', (bool) ($configuration['Enabled'] ?? false));
            $this->writeValue(
                'Zone' . $zone . 'Runtime',
                max(1, min(240, (int) ($configuration['RuntimeMinutes'] ?? 10)))
            );
            $this->writeValue('Zone' . $zone . 'Progress', '');
        }
    }

    private function zoneAutomatic(int $zone): bool
    {
        return $this->getValueBoolean('Zone' . $zone . 'Automatic');
    }

    private function zoneRuntimeMinutes(int $zone): int
    {
        return max(1, min(240, $this->getValueInteger('Zone' . $zone . 'Runtime')));
    }

    private function pumpDelaySeconds(): int
    {
        return max(0, min(300, $this->getValueInteger('PumpLeadSeconds')));
    }

    private function zoneDelaySeconds(): int
    {
        return max(0, min(300, $this->getValueInteger('InterZoneSeconds')));
    }

    private function switchPresentation(): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH];
    }

    private function actionPresentation(string $caption): array
    {
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'LAYOUT' => 1,
            'DISPLAY' => 0,
            'OPTIONS' => json_encode([
                ['Value' => true, 'Caption' => $caption, 'IconActive' => false]
            ]) ?: '[]'
        ];
    }

    private function minutesPresentation(): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_INPUT, 'SUFFIX' => ' min'];
    }

    private function secondsPresentation(): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_INPUT, 'SUFFIX' => ' s'];
    }

    private function valuePresentation(): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
    }

    private function updateZoneProfile(): void
    {
        $profile = 'IRRKNX.Zone.' . $this->InstanceID;
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1);
            IPS_SetVariableProfileIcon($profile, 'Drops');
        }
        IPS_SetVariableProfileAssociation($profile, 0, $this->Translate('Off / all'), '', -1);
        for ($zone = 1; $zone <= self::MAX_ZONES; $zone++) {
            IPS_SetVariableProfileAssociation($profile, $zone, $this->zoneName($zone), '', -1);
        }
        IPS_SetVariableCustomProfile($this->GetIDForIdent('ManualZone'), $profile);
        IPS_SetVariableCustomProfile($this->GetIDForIdent('CurrentZone'), $profile);
    }

    private function initializeStateProfile(): void
    {
        $profile = 'IRRKNX.State.' . $this->InstanceID;
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1);
            IPS_SetVariableProfileIcon($profile, 'Drops');
        }
        foreach ($this->stateDefinitions() as $state => $definition) {
            IPS_SetVariableProfileAssociation($profile, $state, $this->Translate($definition[0]), '', $definition[1]);
        }
        IPS_SetVariableCustomProfile($this->GetIDForIdent('State'), $profile);
        $state = $this->getValueInteger('State');
        $step = in_array($state, [self::STATE_RUNNING, self::STATE_PAUSED], true)
            ? $this->getActiveStepZones()
            : $this->nextQueuedStep();
        $this->setStateForStep($state, $step);
    }

    private function setState(int $state, int $zoneNumber = 0, string $stepName = ''): void
    {
        $definitions = $this->stateDefinitions();
        $definition = $definitions[$state] ?? ['Error', 0xD32F2F];
        $caption = $this->Translate($definition[0]);
        if ($zoneNumber > 0 || $stepName !== '') {
            $zoneName = $stepName !== '' ? $stepName : $this->zoneName($zoneNumber);
            if ($state === self::STATE_OPENING_MASTER) {
                $caption = sprintf($this->Translate('Preparing: %s'), $zoneName);
            } elseif ($state === self::STATE_RUNNING) {
                $caption = sprintf($this->Translate('Watering: %s'), $zoneName);
            } elseif ($state === self::STATE_INTER_ZONE) {
                $caption = sprintf($this->Translate('Next zone: %s'), $zoneName);
            } elseif ($state === self::STATE_PAUSED) {
                $caption = sprintf($this->Translate('Paused: %s'), $zoneName);
            }
        }
        $profile = 'IRRKNX.State.' . $this->InstanceID;
        if (IPS_VariableProfileExists($profile)) {
            IPS_SetVariableProfileAssociation($profile, $state, $caption, '', $definition[1]);
        }
        $this->writeValue('State', $state);
    }

    private function setStateForStep(int $state, array $zoneNumbers): void
    {
        $firstZone = (int) ($zoneNumbers[0] ?? 0);
        $this->setState($state, $firstZone, count($zoneNumbers) > 0 ? $this->stepName($zoneNumbers) : '');
    }

    private function refreshStateDisplay(): void
    {
        $state = $this->getValueInteger('State');
        $step = in_array($state, [self::STATE_RUNNING, self::STATE_PAUSED], true)
            ? $this->getActiveStepZones()
            : $this->nextQueuedStep();
        $this->setStateForStep($state, $step);
    }

    private function stateDefinitions(): array
    {
        return [
            self::STATE_IDLE => ['Idle', 0x43A047],
            self::STATE_OPENING_MASTER => ['Opening pump and main valve', 0xF9A825],
            self::STATE_RUNNING => ['Watering', 0x2196F3],
            self::STATE_INTER_ZONE => ['Changing zone', 0xF9A825],
            self::STATE_BLOCKED => ['Blocked', 0xE67E22],
            self::STATE_ERROR => ['Error', 0xD32F2F],
            self::STATE_STOPPING => ['Waiting for closed feedback', 0xF9A825],
            self::STATE_PAUSED => ['Paused', 0xF9A825]
        ];
    }

    private function defaultZonesJson(): string
    {
        $zones = [];
        for ($zone = 1; $zone <= self::MAX_ZONES; $zone++) {
            $zones[] = $this->defaultZone($zone);
        }
        return json_encode($zones) ?: '[]';
    }

    private function defaultZone(int $zone): array
    {
        return [
            'Enabled' => false,
            'Name' => 'Zone ' . $zone,
            'Valve1ID' => 0,
            'Valve2ID' => 0,
            'Feedback1ID' => 0,
            'Feedback2ID' => 0,
            'Feedback1Inverted' => false,
            'Feedback2Inverted' => false,
            'RainSensitive' => true,
            'Group' => min(100, $zone),
            'RuntimeMinutes' => 10
        ];
    }

    private function isRunning(): bool
    {
        return $this->getValueBoolean('ProgramActive');
    }

    private function setError(string $message): void
    {
        $this->writeValue('LastError', $message);
        $this->SendDebug('Error', $message, 0);
    }

    private function log(string $message): void
    {
        $this->SendDebug('Irrigation', $message, 0);
        if ($this->ReadPropertyBoolean('LogOperations')) {
            $this->LogMessage($message, KL_NOTIFY);
        }
    }

    private function writeValue(string $ident, $value): void
    {
        $id = $this->GetIDForIdent($ident);
        if (GetValue($id) !== $value) {
            SetValue($id, $value);
        }
    }

    private function getValueBoolean(string $ident): bool
    {
        return (bool) GetValue($this->GetIDForIdent($ident));
    }

    private function getValueInteger(string $ident): int
    {
        return (int) GetValue($this->GetIDForIdent($ident));
    }

    private function variableExists(int $id): bool
    {
        return $id > 0 && IPS_VariableExists($id);
    }

    private function isBooleanVariable(int $id): bool
    {
        return $this->variableExists($id) && (int) IPS_GetVariable($id)['VariableType'] === 0;
    }

    private function registerProfiles(): void
    {
        if (!IPS_VariableProfileExists('IRRKNX.State')) {
            IPS_CreateVariableProfile('IRRKNX.State', 1);
            IPS_SetVariableProfileIcon('IRRKNX.State', 'Drops');
        }
        IPS_SetVariableProfileAssociation('IRRKNX.State', self::STATE_IDLE, $this->Translate('Idle'), '', 0x43A047);
        IPS_SetVariableProfileAssociation('IRRKNX.State', self::STATE_OPENING_MASTER, $this->Translate('Opening pump and main valve'), '', 0xF9A825);
        IPS_SetVariableProfileAssociation('IRRKNX.State', self::STATE_RUNNING, $this->Translate('Watering'), '', 0x2196F3);
        IPS_SetVariableProfileAssociation('IRRKNX.State', self::STATE_INTER_ZONE, $this->Translate('Changing zone'), '', 0xF9A825);
        IPS_SetVariableProfileAssociation('IRRKNX.State', self::STATE_BLOCKED, $this->Translate('Blocked'), '', 0xE67E22);
        IPS_SetVariableProfileAssociation('IRRKNX.State', self::STATE_ERROR, $this->Translate('Error'), '', 0xD32F2F);
        IPS_SetVariableProfileAssociation('IRRKNX.State', self::STATE_STOPPING, $this->Translate('Waiting for closed feedback'), '', 0xF9A825);
        IPS_SetVariableProfileAssociation('IRRKNX.State', self::STATE_PAUSED, $this->Translate('Paused'), '', 0xF9A825);
        if (!IPS_VariableProfileExists('IRRKNX.Mode')) {
            IPS_CreateVariableProfile('IRRKNX.Mode', 1);
            IPS_SetVariableProfileIcon('IRRKNX.Mode', 'Drops');
        }
        IPS_SetVariableProfileAssociation('IRRKNX.Mode', 0, $this->Translate('No watering'), '', 0x8C8C8C);
        IPS_SetVariableProfileAssociation('IRRKNX.Mode', 1, $this->Translate('Water zones individually'), '', 0x2196F3);
        IPS_SetVariableProfileAssociation('IRRKNX.Mode', 2, $this->Translate('Water groups'), '', 0x43A047);
        if (!IPS_VariableProfileExists('IRRKNX.Zone')) {
            IPS_CreateVariableProfile('IRRKNX.Zone', 1);
            IPS_SetVariableProfileIcon('IRRKNX.Zone', 'Drops');
        }
        IPS_SetVariableProfileAssociation('IRRKNX.Zone', 0, $this->Translate('Off / all'), '', -1);
        for ($zone = 1; $zone <= self::MAX_ZONES; $zone++) {
            IPS_SetVariableProfileAssociation('IRRKNX.Zone', $zone, sprintf($this->Translate('Zone %d'), $zone), '', -1);
        }
        if (!IPS_VariableProfileExists('IRRKNX.Minutes')) {
            IPS_CreateVariableProfile('IRRKNX.Minutes', 1);
            IPS_SetVariableProfileIcon('IRRKNX.Minutes', 'Clock');
            IPS_SetVariableProfileValues('IRRKNX.Minutes', 1, 240, 1);
            IPS_SetVariableProfileText('IRRKNX.Minutes', '', ' min');
        }
        if (!IPS_VariableProfileExists('IRRKNX.Seconds')) {
            IPS_CreateVariableProfile('IRRKNX.Seconds', 1);
            IPS_SetVariableProfileIcon('IRRKNX.Seconds', 'Clock');
            IPS_SetVariableProfileValues('IRRKNX.Seconds', 0, 86400, 1);
            IPS_SetVariableProfileText('IRRKNX.Seconds', '', ' s');
        }
    }
}
