<?php

declare(strict_types=1);

class IrrigationKNX extends IPSModule
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
        $this->RegisterPropertyString('MainValves', '[{"Enabled":false,"Name":"Main valve 1","VariableID":0,"FeedbackID":0,"FeedbackInverted":false},{"Enabled":false,"Name":"Main valve 2","VariableID":0,"FeedbackID":0,"FeedbackInverted":false}]');
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

        $this->RegisterPropertyInteger('MasterLeadSeconds', 2);
        $this->RegisterPropertyInteger('InterZoneSeconds', 2);
        $this->RegisterPropertyInteger('FeedbackTimeoutSeconds', 10);
        $this->RegisterPropertyInteger('MaximumProgramMinutes', 180);
        $this->RegisterPropertyBoolean('LogOperations', true);

        $this->RegisterTimer('ScheduleTimer', 0, 'IRRKNX_CheckSchedule($_IPS["TARGET"]);');
        $this->RegisterTimer('RunTimer', 0, 'IRRKNX_Tick($_IPS["TARGET"]);');

        $this->registerProfiles();

        $this->RegisterVariableBoolean('Automatic', $this->Translate('Automatic operation'), '~Switch', 10);
        $this->EnableAction('Automatic');
        $this->RegisterVariableBoolean('ProgramActive', $this->Translate('Irrigation program'), '~Switch', 20);
        $this->EnableAction('ProgramActive');
        $this->RegisterVariableInteger('ManualZone', $this->Translate('Manual zone'), 'IRRKNX.Zone', 30);
        $this->EnableAction('ManualZone');
        $this->RegisterVariableInteger('ManualRuntime', $this->Translate('Manual runtime'), 'IRRKNX.Minutes', 40);
        $this->EnableAction('ManualRuntime');
        $this->RegisterVariableBoolean('Pause', $this->Translate('Pause'), '~Switch', 50);
        $this->EnableAction('Pause');
        $this->RegisterVariableBoolean('EmergencyStop', $this->Translate('Emergency stop'), '~Switch', 60);
        $this->EnableAction('EmergencyStop');

        $this->RegisterVariableInteger('State', $this->Translate('State'), 'IRRKNX.State', 100);
        $this->RegisterVariableInteger('CurrentZone', $this->Translate('Current zone'), 'IRRKNX.Zone', 110);
        $this->RegisterVariableInteger('RemainingSeconds', $this->Translate('Remaining time'), 'IRRKNX.Seconds', 120);
        $this->RegisterVariableBoolean('SensorBlocked', $this->Translate('Blocked by sensor'), '~Alert', 130);
        $this->RegisterVariableString('LastError', $this->Translate('Last error'), '', 140);
        $this->RegisterVariableString('OutputState', $this->Translate('Output state'), '', 150);
    }

    public function Migrate($JSONData)
    {
        parent::Migrate($JSONData);
        $data = json_decode((string) $JSONData);
        if (!is_object($data) || !isset($data->configuration) || !isset($data->configuration->Zones)) {
            return '';
        }

        $zones = json_decode((string) $data->configuration->Zones, true);
        if (!is_array($zones) || count($zones) >= self::MAX_ZONES) {
            return '';
        }
        for ($zone = count($zones) + 1; $zone <= self::MAX_ZONES; $zone++) {
            $zones[] = $this->defaultZone($zone);
        }
        $data->configuration->Zones = json_encode($zones);
        return json_encode($data);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Register newly introduced runtime controls for existing instances as well.
        $this->RegisterVariableBoolean('Pause', $this->Translate('Pause'), '~Switch', 50);
        $this->EnableAction('Pause');
        $this->RegisterVariableBoolean('EmergencyStop', $this->Translate('Emergency stop'), '~Switch', 60);
        $this->EnableAction('EmergencyStop');
        $this->registerProfiles();
        $this->unsubscribeVariables();
        if ($this->isRunning()) {
            $this->safeShutdown($this->Translate('Recovered interrupted run or configuration change'), true);
        }
        $errors = $this->validateConfiguration();

        if ($this->GetBuffer('VariablesInitialized') !== '1') {
            $this->writeValue('Automatic', $this->ReadPropertyBoolean('AutomaticDefault'));
            $this->writeValue('ManualRuntime', 10);
            $this->writeValue('State', self::STATE_IDLE);
            $this->writeValue('CurrentZone', 0);
            $this->writeValue('RemainingSeconds', 0);
            $this->writeValue('LastError', '');
            $this->SetBuffer('VariablesInitialized', '1');
        }
        $this->updateZoneProfile();

        if (count($errors) > 0) {
            $this->SetTimerInterval('ScheduleTimer', 0);
            if ($this->GetBuffer('ShutdownPending') !== '1') {
                $this->SetTimerInterval('RunTimer', 0);
            }
            $this->writeValue('LastError', implode('; ', $errors));
            $this->SetStatus(self::STATUS_INVALID_CONFIGURATION);
            return;
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

            case 'EmergencyStop':
                $this->writeValue('EmergencyStop', false);
                $this->EmergencyStop($this->Translate('Emergency stop requested'));
                break;

            default:
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
        if ($this->isRunning() && $this->isBlockedBySensors()) {
            $this->safeShutdown($this->Translate('Safety stop: rain or soil moisture limit reached'), true);
            return;
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
        $queue = [];
        foreach ($this->getZones() as $index => $zone) {
            if (($zone['Enabled'] ?? false) && $this->zoneHasOutput($zone)) {
                $queue[] = $index + 1;
            }
        }

        return $this->startQueue($queue, $Manual ? 'manual-program' : 'automatic', 0);
    }

    public function StartZone(int $Zone, int $RuntimeMinutes = 0): bool
    {
        if ($Zone < 1 || $Zone > self::MAX_ZONES) {
            $this->setError($this->Translate('Invalid zone number'));
            return false;
        }

        $zones = $this->getZones();
        $zone = $zones[$Zone - 1] ?? null;
        if (!is_array($zone) || !($zone['Enabled'] ?? false) || !$this->zoneHasOutput($zone)) {
            $this->setError(sprintf($this->Translate('Zone %d is not enabled or has no valve'), $Zone));
            return false;
        }

        return $this->startQueue([$Zone], 'manual-zone', max(1, $RuntimeMinutes));
    }

    public function Stop(): void
    {
        $this->safeShutdown($this->Translate('Stopped by user'), false);
    }

    public function EmergencyStop(string $Reason = ''): void
    {
        $reason = $Reason !== '' ? $Reason : $this->Translate('Emergency stop');
        $this->safeShutdown($reason, true);
    }

    public function Pause(): bool
    {
        if (!$this->isRunning() || $this->GetBuffer('Phase') !== 'running-zone') {
            $this->setError($this->Translate('Pause is only possible while a zone is watering'));
            return false;
        }

        $zoneNumber = $this->getValueInteger('CurrentZone');
        $zone = $this->getZones()[$zoneNumber - 1] ?? null;
        if (!is_array($zone)) {
            $this->safeShutdown($this->Translate('Unable to pause an invalid zone'), true);
            return false;
        }

        $remaining = max(1, (int) $this->GetBuffer('PhaseDeadline') - time());
        $this->SetBuffer('PausedRemainingSeconds', (string) $remaining);
        $this->SetBuffer('PauseStartedAt', (string) time());
        if (!$this->setZoneValves($zone, false) || !$this->setMainValves(false)) {
            $this->safeShutdown($this->Translate('Unable to close valves for pause'), true);
            return false;
        }

        $this->SetBuffer('Phase', 'paused');
        $this->SetBuffer('PhaseDeadline', '0');
        $this->writeValue('Pause', true);
        $this->writeValue('RemainingSeconds', $remaining);
        $this->writeValue('State', self::STATE_PAUSED);
        $this->armFeedbackDeadline();
        $this->log(sprintf($this->Translate('Zone %s paused with %d second(s) remaining'), $this->zoneName($zoneNumber), $remaining));
        return true;
    }

    public function Resume(): bool
    {
        if (!$this->isRunning() || $this->GetBuffer('Phase') !== 'paused') {
            $this->setError($this->Translate('No paused irrigation run is available'));
            return false;
        }
        $this->refreshSensorState();
        if ($this->isBlockedBySensors()) {
            $this->setError($this->Translate('Resume blocked by rain or soil moisture sensor'));
            return false;
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
        if (!$this->setMainValves(true)) {
            $this->safeShutdown($this->Translate('Unable to reopen a main valve after pause'), true);
            return false;
        }

        $this->writeValue('LastError', '');
        $this->writeValue('Pause', false);
        $this->writeValue('State', self::STATE_OPENING_MASTER);
        $this->SetBuffer('Phase', 'resuming-master');
        $this->SetBuffer('PhaseDeadline', (string) (time() + max(0, $this->ReadPropertyInteger('MasterLeadSeconds'))));
        $this->armFeedbackDeadline();
        if ($this->ReadPropertyInteger('MasterLeadSeconds') === 0 && $this->feedbackMatchesExpected()) {
            $this->resumeCurrentZone();
        }
        return true;
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
        if ($this->isBlockedBySensors()) {
            $this->safeShutdown($this->Translate('Safety stop: rain or soil moisture limit reached'), true);
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
            $this->writeValue('RemainingSeconds', max(0, $deadline - $now));
            if ($now >= $deadline) {
                $this->finishCurrentZone();
            }
            return;
        }

        if ($phase === 'inter-zone' && $now >= $deadline) {
            $this->advanceQueue();
        }
    }

    private function startQueue(array $queue, string $source, int $runtimeOverrideMinutes): bool
    {
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
        if ($this->isBlockedBySensors()) {
            $this->writeValue('State', self::STATE_BLOCKED);
            $this->setError($this->Translate('Start blocked by rain or soil moisture sensor'));
            return false;
        }

        $this->SetBuffer('Queue', json_encode(array_values($queue)) ?: '[]');
        $this->SetBuffer('QueuePosition', '0');
        $this->SetBuffer('RunSource', $source);
        $this->SetBuffer('RuntimeOverrideMinutes', (string) $runtimeOverrideMinutes);
        $this->SetBuffer('ProgramStartedAt', (string) time());
        $this->SetBuffer('RunSimulation', $this->ReadPropertyBoolean('Simulation') ? '1' : '0');
        $this->SetBuffer('RunFeedbackDefinitions', json_encode($this->getOutputDefinitions()) ?: '[]');
        $this->SetBuffer('StopInProgress', '0');
        $this->writeValue('LastError', '');
        $this->writeValue('ProgramActive', true);
        $this->writeValue('Pause', false);
        $this->writeValue('CurrentZone', 0);
        $this->writeValue('State', self::STATE_OPENING_MASTER);

        if (!$this->setMainValves(true)) {
            $this->safeShutdown($this->Translate('Unable to open a main valve'), true);
            return false;
        }

        $this->SetBuffer('Phase', 'opening-master');
        $this->SetBuffer('PhaseDeadline', (string) (time() + max(0, $this->ReadPropertyInteger('MasterLeadSeconds'))));
        $this->armFeedbackDeadline();
        $this->SetTimerInterval('RunTimer', 1000);
        $this->log(sprintf('Irrigation started (%s)', $source));

        if ($this->ReadPropertyInteger('MasterLeadSeconds') === 0 && $this->feedbackMatchesExpected()) {
            $this->startCurrentZone();
        }
        return true;
    }

    private function startCurrentZone(): void
    {
        $queue = $this->getQueue();
        $position = (int) $this->GetBuffer('QueuePosition');
        $zoneNumber = (int) ($queue[$position] ?? 0);
        $zones = $this->getZones();
        $zone = $zones[$zoneNumber - 1] ?? null;
        if (!is_array($zone)) {
            $this->safeShutdown($this->Translate('Queue contains an invalid zone'), true);
            return;
        }

        if (!$this->setZoneValves($zone, true)) {
            $this->safeShutdown(sprintf($this->Translate('Unable to open zone %d'), $zoneNumber), true);
            return;
        }

        if ($this->GetBuffer('RunSimulation') === '1') {
            $minutes = max(1, min(240, $this->ReadPropertyInteger('SimulationRuntimeMinutes')));
        } else {
            $override = (int) $this->GetBuffer('RuntimeOverrideMinutes');
            $minutes = $override > 0 ? $override : max(1, (int) ($zone['RuntimeMinutes'] ?? 1));
        }
        $deadline = time() + ($minutes * 60);

        $this->SetBuffer('Phase', 'running-zone');
        $this->SetBuffer('PhaseDeadline', (string) $deadline);
        $this->writeValue('CurrentZone', $zoneNumber);
        $this->writeValue('RemainingSeconds', $minutes * 60);
        $this->writeValue('State', self::STATE_RUNNING);
        $this->armFeedbackDeadline();
        $this->log(sprintf('Zone %d opened for %d minute(s)', $zoneNumber, $minutes));
    }

    private function resumeCurrentZone(): void
    {
        $zoneNumber = $this->getValueInteger('CurrentZone');
        $zone = $this->getZones()[$zoneNumber - 1] ?? null;
        if (!is_array($zone)) {
            $this->safeShutdown($this->Translate('Unable to resume an invalid zone'), true);
            return;
        }
        if (!$this->setZoneValves($zone, true)) {
            $this->safeShutdown(sprintf($this->Translate('Unable to reopen zone %s'), $this->zoneName($zoneNumber)), true);
            return;
        }

        $remaining = max(1, (int) $this->GetBuffer('PausedRemainingSeconds'));
        $this->SetBuffer('Phase', 'running-zone');
        $this->SetBuffer('PhaseDeadline', (string) (time() + $remaining));
        $this->SetBuffer('PauseStartedAt', '0');
        $this->writeValue('RemainingSeconds', $remaining);
        $this->writeValue('State', self::STATE_RUNNING);
        $this->armFeedbackDeadline();
        $this->log(sprintf($this->Translate('Zone %s resumed with %d second(s) remaining'), $this->zoneName($zoneNumber), $remaining));
    }

    private function finishCurrentZone(): void
    {
        $zoneNumber = $this->getValueInteger('CurrentZone');
        $zones = $this->getZones();
        $zone = $zones[$zoneNumber - 1] ?? null;
        if (is_array($zone)) {
            $this->setZoneValves($zone, false);
        }

        $this->writeValue('RemainingSeconds', 0);
        $this->log(sprintf('Zone %d closed', $zoneNumber));
        $position = (int) $this->GetBuffer('QueuePosition') + 1;
        $this->SetBuffer('QueuePosition', (string) $position);

        if ($position >= count($this->getQueue())) {
            $this->safeShutdown($this->Translate('Program completed'), false);
            return;
        }

        $this->SetBuffer('Phase', 'inter-zone');
        $this->SetBuffer('PhaseDeadline', (string) (time() + max(0, $this->ReadPropertyInteger('InterZoneSeconds'))));
        $this->writeValue('CurrentZone', 0);
        $this->writeValue('State', self::STATE_INTER_ZONE);
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
        $this->writeValue('ManualZone', 0);
        $this->writeValue('CurrentZone', 0);
        $this->writeValue('RemainingSeconds', 0);
        $this->SetBuffer('PausedRemainingSeconds', '0');
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
            $this->writeValue('State', self::STATE_STOPPING);
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

    private function setZoneValves(array $zone, bool $state): bool
    {
        $success = true;
        foreach ($this->getValveIDs($zone) as $id) {
            $success = $this->switchOutput($id, $state) && $success;
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
            $actual = (bool) GetValue($feedbackID);
            if ((bool) ($definition['FeedbackInverted'] ?? false)) {
                $actual = !$actual;
            }
            if ($actual !== (bool) $expected[(string) $outputID]) {
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
        $this->writeValue('State', $finalState);
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
            $this->writeValue('State', self::STATE_IDLE);
        }
    }

    private function isBlockedBySensors(): bool
    {
        $rainID = $this->ReadPropertyInteger('RainSensorID');
        if ($rainID > 0 && IPS_VariableExists($rainID)) {
            if ((bool) GetValue($rainID) === $this->ReadPropertyBoolean('RainActiveValue')) {
                return true;
            }
        }

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
        return $difference >= 0 && $difference % $interval === 0;
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
        return array_slice($this->decodeListProperty('Zones'), 0, self::MAX_ZONES);
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
            if (!is_array($zone) || !($zone['Enabled'] ?? false)) {
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
        return is_array($queue) ? array_map('intval', $queue) : [];
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
            IPS_LogMessage('KNX Irrigation', $message);
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
        IPS_SetVariableProfileAssociation('IRRKNX.State', self::STATE_OPENING_MASTER, $this->Translate('Opening main valves'), '', 0xF9A825);
        IPS_SetVariableProfileAssociation('IRRKNX.State', self::STATE_RUNNING, $this->Translate('Watering'), '', 0x2196F3);
        IPS_SetVariableProfileAssociation('IRRKNX.State', self::STATE_INTER_ZONE, $this->Translate('Changing zone'), '', 0xF9A825);
        IPS_SetVariableProfileAssociation('IRRKNX.State', self::STATE_BLOCKED, $this->Translate('Blocked'), '', 0xE67E22);
        IPS_SetVariableProfileAssociation('IRRKNX.State', self::STATE_ERROR, $this->Translate('Error'), '', 0xD32F2F);
        IPS_SetVariableProfileAssociation('IRRKNX.State', self::STATE_STOPPING, $this->Translate('Waiting for closed feedback'), '', 0xF9A825);
        IPS_SetVariableProfileAssociation('IRRKNX.State', self::STATE_PAUSED, $this->Translate('Paused'), '', 0xF9A825);
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
