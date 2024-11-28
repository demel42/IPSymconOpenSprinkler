<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';
require_once __DIR__ . '/../libs/local.php';

class OpenSprinkler extends IPSModule
{
    use OpenSprinkler\StubsCommonLib;
    use OpenSprinklerLocalLib;

    public static $MAX_INT_SENSORS = 2;

    private $VarProf_Zones;
    private $VarProf_Programs;

    public function __construct(string $InstanceID)
    {
        parent::__construct($InstanceID);

        $this->CommonConstruct(__DIR__);

        $this->VarProf_Zones = 'OpenSprinkler.Zones_' . $this->InstanceID;
        $this->VarProf_Programs = 'OpenSprinkler.Programs_' . $this->InstanceID;
    }

    public function __destruct()
    {
        $this->CommonDestruct();
    }

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('module_disable', false);

        $this->RegisterPropertyString('host', '');
        $this->RegisterPropertyBoolean('use_https', true);
        $this->RegisterPropertyInteger('port', 0);
        $this->RegisterPropertyString('password', '');

        $this->RegisterPropertyInteger('query_interval', 60);

        $this->RegisterPropertyString('mqtt_topic', 'opensprinkler');

        $this->RegisterPropertyString('zone_list', json_encode([]));
        $this->RegisterPropertyString('sensor_list', json_encode([]));
        $this->RegisterPropertyString('program_list', json_encode([]));

        $this->RegisterPropertyString('variables_mqtt_topic', 'opensprinkler/variables');
        $this->RegisterPropertyString('variable_list', json_encode([]));
        $this->RegisterPropertyInteger('send_interval', 300);

        $this->RegisterAttributeInteger('timezone_offset', 0);
        $this->RegisterAttributeInteger('pulse_volume', 0);

        $this->RegisterAttributeString('zone_infos', json_encode([]));
        $this->RegisterAttributeString('sensor_infos', json_encode([]));
        $this->RegisterAttributeString('program_infos', json_encode([]));

        $this->RegisterAttributeString('UpdateInfo', json_encode([]));
        $this->RegisterAttributeString('ModuleStats', json_encode([]));

        $this->InstallVarProfiles(false);

        $this->CreateVarProfile($this->VarProf_Zones, VARIABLETYPE_INTEGER, '', 0, 0, 0, 0, '', [['Wert' => 0, 'Name' => '-']], false);
        $this->CreateVarProfile($this->VarProf_Programs, VARIABLETYPE_INTEGER, '', 0, 0, 0, 0, '', [['Wert' => 0, 'Name' => '-']], false);

        $this->RegisterTimer('QueryStatus', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "QueryStatus", "");');
        $this->RegisterTimer('SendVariables', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "SendVariables", "");');

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);

        $this->ConnectParent('{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}');
    }

    public function MessageSink($timestamp, $senderID, $message, $data)
    {
        parent::MessageSink($timestamp, $senderID, $message, $data);

        if ($message == IPS_KERNELMESSAGE && $data[0] == KR_READY) {
            $this->SetQueryInterval();
            $this->SetSendInterval();
        }

        if (IPS_GetKernelRunlevel() == KR_READY && $message == VM_UPDATE && $data[1] == true /* changed */) {
            $this->SendDebug(__FUNCTION__, 'timestamp=' . $timestamp . ', senderID=' . $senderID . ', message=' . $message . ', data=' . print_r($data, true), 0);
        }
    }

    private function CheckModuleConfiguration()
    {
        $r = [];

        $host = $this->ReadPropertyString('host');
        if ($host == '') {
            $this->SendDebug(__FUNCTION__, '"host" is needed', 0);
            $r[] = $this->Translate('Host must be specified');
        }

        $password = $this->ReadPropertyString('password');
        if ($password == '') {
            $this->SendDebug(__FUNCTION__, '"password" is needed', 0);
            $r[] = $this->Translate('Password must be specified');
        }

        return $r;
    }

    private function CheckModuleUpdate(array $oldInfo, array $newInfo)
    {
        $r = [];

        return $r;
    }

    private function CompleteModuleUpdate(array $oldInfo, array $newInfo)
    {
        return '';
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->MaintainReferences();
        $varIDs = [];
        $variable_list = @json_decode($this->ReadPropertyString('variable_list'), true);
        if ($variable_list === false) {
            $variable_list = [];
        }
        foreach ($variable_list as $variable) {
            $varID = $variable['varID'];
            if ($this->IsValidID($varID) && IPS_VariableExists($varID)) {
                $this->RegisterReference($varID);
                $varIDs[] = $varID;
            }
        }

        $this->UnregisterMessages([VM_UPDATE]);

        if ($this->CheckPrerequisites() != false) {
            $this->MaintainTimer('QueryStatus', 0);
            $this->MaintainTimer('SendVariables', 0);
            $this->MaintainStatus(self::$IS_INVALIDPREREQUISITES);
            return;
        }

        if ($this->CheckUpdate() != false) {
            $this->MaintainTimer('QueryStatus', 0);
            $this->MaintainTimer('SendVariables', 0);
            $this->MaintainStatus(self::$IS_UPDATEUNCOMPLETED);
            return;
        }

        if ($this->CheckConfiguration() != false) {
            $this->MaintainTimer('QueryStatus', 0);
            $this->MaintainTimer('SendVariables', 0);
            $this->MaintainStatus(self::$IS_INVALIDCONFIG);
            return;
        }

        // 1..100: Controller
        $vpos = 1;

        $this->MaintainVariable('ControllerState', $this->Translate('Controller state'), VARIABLETYPE_INTEGER, 'OpenSprinkler.ControllerState', $vpos++, true);
        $this->MaintainVariable('WateringLevel', $this->Translate('Watering level'), VARIABLETYPE_INTEGER, 'OpenSprinkler.WateringLevel', $vpos++, true);
        $this->MaintainVariable('RainDelayUntil', $this->Translate('Rain delay until'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, true);

        $this->MaintainVariable('CurrentDraw', $this->Translate('Current draw (actual)'), VARIABLETYPE_INTEGER, 'OpenSprinkler.Current', $vpos++, true);

        $this->MaintainVariable('WeatherQueryTstamp', $this->Translate('Timestamp of last weather information'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, true);
        $this->MaintainVariable('WeatherQueryStatus', $this->Translate('Status of last weather query'), VARIABLETYPE_INTEGER, 'OpenSprinkler.WeatherQueryStatus', $vpos++, true);

        $this->MaintainVariable('DeviceTime', $this->Translate('Device time'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, true);
        $this->MaintainVariable('WifiStrength', $this->Translate('Wifi signal strenght'), VARIABLETYPE_INTEGER, 'OpenSprinkler.Wifi', $vpos++, true);

        $this->MaintainVariable('LastRebootTstamp', $this->Translate('Timestamp of last reboot'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, true);
        $this->MaintainVariable('LastRebootCause', $this->Translate('Cause of last reboot'), VARIABLETYPE_INTEGER, 'OpenSprinkler.RebootCause', $vpos++, true);

        $this->MaintainVariable('LastUpdate', $this->Translate('Last update'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, true);

        $varList = [];

        // 201..399: internal Sensors (max 2)
        $sensor_list = @json_decode($this->ReadPropertyString('sensor_list'), true);
        if ($sensor_list === false) {
            $sensor_list = [];
        }
        for ($sensor_n = 0; $sensor_n < count($sensor_list); $sensor_n++) {
            $sensor_entry = $sensor_list[$sensor_n];
            if ($sensor_entry['no'] > self::$MAX_INT_SENSORS) {
                continue;
            }

            $vpos = 200 + $sensor_n * 100 + 1;
            $post = '_' . ($sensor_n + 1);
            $s = sprintf('SN%d: ', $sensor_n + 1);

            $use = (bool) $this->GetArrayElem($sensor_entry, 'use', false);
            $snt = $this->GetArrayElem($sensor_entry, 'type', self::$SENSOR_TYPE_NONE);

            if ($use && in_array($snt, [self::$SENSOR_TYPE_RAIN, self::$SENSOR_TYPE_SOIL])) {
                $this->MaintainVariable('SensorState' . $post, $s . $this->SensorType2String($snt), VARIABLETYPE_BOOLEAN, 'OpenSprinkler.SensorState', $vpos++, $use);
                $varList[] = 'SensorState' . $post;
            }

            if ($use && $snt == self::$SENSOR_TYPE_FLOW) {
                $this->MaintainVariable('WaterFlowrate', $s . $this->Translate('Water flow rate (actual)'), VARIABLETYPE_FLOAT, 'OpenSprinkler.WaterFlowrate', $vpos++, $use);
                $varList[] = 'WaterFlowrate';
                // $this->MaintainVariable('DailyWaterUsage', $s . $this->Translate('Water usage (today)'), VARIABLETYPE_FLOAT, 'OpenSprinkler.Flowmeter', $vpos++, $with_daily_value);
            }
        }

        $vpos = 801;
        $this->MaintainVariable('ZoneSelection', $this->Translate('Zone selection'), VARIABLETYPE_INTEGER, $this->VarProf_Zones, $vpos++, true);
        $this->MaintainAction('ZoneSelection', true);

        $this->MaintainVariable('ZoneState', $this->Translate('Zone state'), VARIABLETYPE_INTEGER, 'OpenSprinkler.ZoneState', $vpos++, $use);
        $this->MaintainVariable('ZoneDisabled', $this->Translate('Zone is disabled'), VARIABLETYPE_BOOLEAN, 'OpenSprinkler.YesNo', $vpos++, true);
        $this->MaintainAction('ZoneDisabled', true);
        $this->MaintainVariable('ZoneIgnoreRain', $this->Translate('Zone ignores rain delay'), VARIABLETYPE_BOOLEAN, 'OpenSprinkler.YesNo', $vpos++, true);
        $this->MaintainAction('ZoneIgnoreRain', true);
        $this->MaintainVariable('ZoneIgnoreSensor1', $this->Translate('Zone ignores sensor 1'), VARIABLETYPE_BOOLEAN, 'OpenSprinkler.YesNo', $vpos++, true);
        $this->MaintainAction('ZoneIgnoreSensor1', true);
        $this->MaintainVariable('ZoneIgnoreSensor2', $this->Translate('Zone ignores sensor 2'), VARIABLETYPE_BOOLEAN, 'OpenSprinkler.YesNo', $vpos++, true);
        $this->MaintainAction('ZoneIgnoreSensor2', true);
        $this->MaintainVariable('ZoneInfo', $this->Translate('Zone information'), VARIABLETYPE_STRING, '', $vpos++, true);

        // 1001..8299: Zones (max 72)
        $zone_list = @json_decode($this->ReadPropertyString('zone_list'), true);
        if ($zone_list === false) {
            $zone_list = [];
        }
        for ($zone_n = 0; $zone_n < count($zone_list); $zone_n++) {
            $zone_entry = $zone_list[$zone_n];

            $vpos = 1000 + $zone_n * 100 + 1;
            $post = '_' . ($zone_n + 1);
            $s = sprintf('Z%02d[%s]: ', $zone_n + 1, $zone_entry['name']);

            $use = (bool) $this->GetArrayElem($zone_entry, 'use', false);

            $this->MaintainVariable('ZoneState' . $post, $s . $this->Translate('Zone state'), VARIABLETYPE_INTEGER, 'OpenSprinkler.ZoneState', $vpos++, $use);
            $varList[] = 'ZoneState' . $post;

            // aktueller Bewässerungszyklus
            $this->MaintainVariable('ZoneTimeLeft' . $post, $s . $this->Translate('Time left'), VARIABLETYPE_INTEGER, 'OpenSprinkler.Duration', $vpos++, $use);
            $varList[] = 'ZoneTimeLeft' . $post;

            // letzter Bewässerungszyklus
            $this->MaintainVariable('ZoneLastRun' . $post, $s . $this->Translate('Last run'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, $use);
            $varList[] = 'ZoneLastRun' . $post;
            $this->MaintainVariable('ZoneLastDuration' . $post, $s . $this->Translate('Duration of last run'), VARIABLETYPE_INTEGER, 'OpenSprinkler.Duration', $vpos++, $use);
            $varList[] = 'ZoneLastDuration' . $post;

            // nächster Bewässerungszyklus
            $this->MaintainVariable('ZoneNextRun' . $post, $s . $this->Translate('Next run'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, $use);
            $varList[] = 'ZoneNextRun' . $post;
            $this->MaintainVariable('ZoneNextDuration' . $post, $s . $this->Translate('Duration of next run'), VARIABLETYPE_INTEGER, 'OpenSprinkler.Duration', $vpos++, $use);
            $varList[] = 'ZoneNextDuration' . $post;
        }

        $vpos = 900;
        $this->MaintainVariable('ProgramSelection', $this->Translate('Program selection'), VARIABLETYPE_INTEGER, $this->VarProf_Programs, $vpos++, true);
        $this->MaintainAction('ProgramSelection', true);
        $this->MaintainVariable('ProgramEnabled', $this->Translate('Program is enabled'), VARIABLETYPE_BOOLEAN, 'OpenSprinkler.YesNo', $vpos++, true);
        $this->MaintainAction('ProgramEnabled', true);
        $this->MaintainVariable('ProgramWeatherAdjust', $this->Translate('Program with weather adjustments'), VARIABLETYPE_BOOLEAN, 'OpenSprinkler.YesNo', $vpos++, true);
        $this->MaintainAction('ProgramWeatherAdjust', true);
        $this->MaintainVariable('ProgramInfo', $this->Translate('Program information'), VARIABLETYPE_STRING, '', $vpos++, true);

        // 10001..14999: Programs (max 40)
        $program_list = @json_decode($this->ReadPropertyString('program_list'), true);
        if ($program_list === false) {
            $program_list = [];
        }
        for ($program_n = 0; $program_n < count($program_list); $program_n++) {
            $program_entry = $program_list[$program_n];

            $vpos = 20000 + $program_n * 100 + 1;
            $post = '_' . ($program_n + 1);
            $s = sprintf('P%02d[%s]: ', $program_n + 1, $program_entry['name']);
        }

        /*
                // aktueller Bewässerungszyklus
                $this->MaintainVariable('WaterUsage', $this->Translate('Water usage'), VARIABLETYPE_FLOAT, 'OpenSprinkler.Flowmeter', $vpos++, $with_waterusage);
                $this->MaintainVariable('WaterFlowrate', $this->Translate('Water flow rate'), VARIABLETYPE_FLOAT, 'OpenSprinkler.WaterFlowrate', $vpos++, $with_flowrate != self::$FLOW_RATE_NONE);

                // Aktionen
                $this->MaintainVariable('ZoneAction', $this->Translate('Zone operation'), VARIABLETYPE_INTEGER, 'OpenSprinkler.ZoneAction', $vpos++, true);
                $this->MaintainVariable('SuspendUntil', $this->Translate('Suspended until end of'), VARIABLETYPE_INTEGER, '~UnixTimestampDate', $vpos++, true);
                $this->MaintainVariable('SuspendAction', $this->Translate('Zone suspension'), VARIABLETYPE_INTEGER, 'OpenSprinkler.ZoneSuspend', $vpos++, true);

                // Status
                $this->MaintainVariable('Workflow', $this->Translate('Current workflow'), VARIABLETYPE_INTEGER, 'OpenSprinkler.ZoneWorkflow', $vpos++, $with_workflow);
                $this->MaintainVariable('Status', $this->Translate('Zone status'), VARIABLETYPE_INTEGER, 'OpenSprinkler.ZoneStatus', $vpos++, $with_status);

                // Tageswerte
                $this->MaintainVariable('DailyDuration', $this->Translate('Watering time (today)'), VARIABLETYPE_INTEGER, 'OpenSprinkler.Duration', $vpos++, $with_daily_value);
                $this->MaintainVariable('DailyWaterUsage', $this->Translate('Water usage (today)'), VARIABLETYPE_FLOAT, 'OpenSprinkler.Flowmeter', $vpos++, $with_daily_value && $with_waterusage);

         */

        $objList = [];
        $chldIDs = IPS_GetChildrenIDs($this->InstanceID);
        foreach ($chldIDs as $chldID) {
            $obj = IPS_GetObject($chldID);
            if ($obj['ObjectType'] == OBJECTTYPE_VARIABLE) {
                if (preg_match('#^Sensor[^_]+_[0-9]+$#', $obj['ObjectIdent'], $r)) {
                    $objList[] = $obj;
                }
                if (preg_match('#^Zone[^_]+_[0-9]+$#', $obj['ObjectIdent'], $r)) {
                    $objList[] = $obj;
                }
                if (preg_match('#^Program[^_]+_[0-9]+$#', $obj['ObjectIdent'], $r)) {
                    $objList[] = $obj;
                }
            }
        }
        foreach ($objList as $obj) {
            $ident = $obj['ObjectIdent'];
            if (!in_array($ident, $varList)) {
                $this->SendDebug(__FUNCTION__, 'unregister variable: ident=' . $ident, 0);
                $this->UnregisterVariable($ident);
            }
        }

        $module_disable = $this->ReadPropertyBoolean('module_disable');
        if ($module_disable) {
            $this->MaintainTimer('QueryStatus', 0);
            $this->MaintainTimer('SendVariables', 0);
            $this->MaintainStatus(IS_INACTIVE);
            return;
        }

        $mqtt_topic = $this->ReadPropertyString('mqtt_topic');
        $this->SetReceiveDataFilter('.*' . $mqtt_topic . '.*');

        foreach ($varIDs as $varID) {
            $this->RegisterMessage($varID, VM_UPDATE);
        }

        $this->MaintainStatus(IS_ACTIVE);

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->SetQueryInterval();
            $this->SetSendInterval();
        }
    }

    private function GetFormElements()
    {
        $formElements = $this->GetCommonFormElements('OpenSprinkler - Irrigation system');

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            return $formElements;
        }

        $formElements[] = [
            'type'    => 'CheckBox',
            'name'    => 'module_disable',
            'caption' => 'Disable instance',
        ];

        $formElements[] = [
            'type'    => 'ExpansionPanel',
            'items'   => [
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'host',
                    'caption' => 'Host',
                ],
                /*
                [
                    'type'    => 'CheckBox',
                    'name'    => 'use_https',
                    'caption' => 'Use HTTPS',
                ],
                 */
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'port',
                    'minimum' => 0,
                    'caption' => 'Port',
                ],
                [
                    'type'    => 'PasswordTextBox',
                    'name'    => 'password',
                    'caption' => 'Password',
                ],
                [
                    'type'   => 'ValidationTextBox',
                    'name'   => 'mqtt_topic',
                    'caption'=> 'MQTT topic',
                ],
            ],
            'caption' => 'Access configuration',
        ];

        $zone_list = @json_decode($this->ReadPropertyString('zone_list'), true);
        if ($zone_list === false) {
            $zone_list = [];
        }
        $sensor_list = @json_decode($this->ReadPropertyString('sensor_list'), true);
        if ($sensor_list === false) {
            $sensor_list = [];
        }
        $program_list = @json_decode($this->ReadPropertyString('program_list'), true);
        if ($program_list === false) {
            $program_list = [];
        }

        $formElements[] = [
            'type'    => 'ExpansionPanel',
            'items'   => [
                [
                    'type'     => 'List',
                    'name'     => 'zone_list',
                    'columns'  => [
                        [
                            'caption' => 'No',
                            'name'    => 'no',
                            'width'   => '50px',
                            'save'    => true,
                        ],
                        [
                            'caption' => 'Name',
                            'name'    => 'name',
                            'width'   => 'auto',
                            'save'    => true,
                        ],
                        [
                            'caption' => 'Group',
                            'name'    => 'group',
                            'width'   => '100px',
                            'save'    => true,
                        ],
                        [
                            'caption' => 'Interface',
                            'name'    => 'interface',
                            'width'   => '200px',
                            'save'    => true,
                        ],
                        [
                            'caption' => 'Information',
                            'name'    => 'info',
                            'width'   => '500px',
                            'save'    => true,
                        ],
                        [
                            'caption' => 'Use',
                            'name'    => 'use',
                            'width'   => '90px',
                            'edit'    => [
                                'type' => 'CheckBox'
                            ],
                        ],
                    ],
                    'values'   => $zone_list,
                    'rowCount' => count($zone_list) > 0 ? count($zone_list) : 1,
                    'add'      => false,
                    'delete'   => false,
                    'caption'  => 'Zones',
                ],
                [
                    'type'     => 'List',
                    'name'     => 'sensor_list',
                    'columns'  => [
                        [
                            'caption' => 'No',
                            'name'    => 'no',
                            'width'   => '50px',
                            'save'    => true,
                        ],
                        [
                            'name'    => 'type',
                            'visible' => false,
                            'save'    => true,
                        ],
                        [
                            'caption' => 'Name',
                            'name'    => 'name',
                            'width'   => 'auto',
                            'save'    => true,
                        ],
                        [
                            'caption' => 'Information',
                            'name'    => 'info',
                            'width'   => '500px',
                            'save'    => true,
                        ],
                        [
                            'caption' => 'Use',
                            'name'    => 'use',
                            'width'   => '90px',
                            'edit'    => [
                                'type' => 'CheckBox'
                            ],
                        ],
                    ],
                    'values'   => $sensor_list,
                    'rowCount' => count($sensor_list) > 0 ? count($sensor_list) : 1,
                    'add'      => false,
                    'delete'   => false,
                    'caption'  => 'Sensors',
                ],
                [
                    'type'     => 'List',
                    'name'     => 'program_list',
                    'columns'  => [
                        [
                            'caption' => 'No',
                            'name'    => 'no',
                            'width'   => '50px',
                            'save'    => true,
                        ],
                        [
                            'caption' => 'Name',
                            'name'    => 'name',
                            'width'   => 'auto',
                            'save'    => true,
                        ],
                        [
                            'caption' => 'Information',
                            'name'    => 'info',
                            'width'   => '800px',
                            'save'    => true,
                        ],
                        [
                            'caption' => 'Use',
                            'name'    => 'use',
                            'width'   => '90px',
                            'edit'    => [
                                'type' => 'CheckBox'
                            ],
                        ],
                    ],
                    'values'   => $program_list,
                    'rowCount' => count($program_list) > 0 ? count($program_list) : 1,
                    'add'      => false,
                    'delete'   => false,
                    'caption'  => 'Programs',
                ],
                [
                    'type'    => 'Button',
                    'caption' => 'Retrive configuration',
                    'onClick' => 'IPS_RequestAction($id, "RetriveConfiguration", "");',
                ],
            ],
            'caption' => 'Controller configuration',
        ];

        /*
            variables
                varID
                mqtt_filter


            beijeden Neustart (ApplyChanges?) alle Variablen übertragen
            übertragungs-intervall
            passender timestamp

         */

        $formElements[] = [
            'type'    => 'ExpansionPanel',
            'items'   => [
                [
                    'type'   => 'ValidationTextBox',
                    'name'   => 'variables_mqtt_topic',
                    'caption'=> 'MQTT topic für sensor values',
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'send_interval',
                    'suffix'  => 'Seconds',
                    'minimum' => 0,
                    'caption' => 'Send variables interval',
                ],
                [
                    'type'    => 'List',
                    'name'    => 'variable_list',
                    'columns' => [
                        [
                            'name'    => 'varID',
                            'add'     => 0,
                            'edit'    => [
                                'type' => 'SelectVariable',
                            ],
                            'width'   => 'auto',
                            'caption' => 'Reference variable',
                        ],
                        [
                            'name'    => 'mqtt_filter',
                            'add'     => '',
                            'edit'    => [
                                'type' => 'ValidationTextBox',
                            ],
                            'width'   => '300px',
                            'caption' => 'Ident on the controller ("MQTT filter")',
                        ],
                        [
                            'add'     => true,
                            'name'    => 'use',
                            'width'   => '90px',
                            'edit'    => [
                                'type' => 'CheckBox'
                            ],
                            'caption' => 'Use',
                        ],
                    ],
                    'add'      => true,
                    'delete'   => true,
                    'caption'  => 'Variables to be transferred',
                ],
            ],
            'caption' => 'External sensor values',
        ];

        $formElements[] = [
            'type'    => 'NumberSpinner',
            'name'    => 'query_interval',
            'suffix'  => 'Seconds',
            'minimum' => 0,
            'caption' => 'Query interval',
        ];

        return $formElements;
    }

    private function GetFormActions()
    {
        $formActions = [];

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            $formActions[] = $this->GetCompleteUpdateFormAction();

            $formActions[] = $this->GetInformationFormAction();
            $formActions[] = $this->GetReferencesFormAction();

            return $formActions;
        }

        $formActions[] = [
            'type'      => 'RowLayout',
            'items'     => [
                [
                    'type'    => 'Button',
                    'caption' => 'Query status',
                    'onClick' => 'IPS_RequestAction($id, "QueryStatus", "");',
                ],
                [
                    'type'    => 'Button',
                    'caption' => 'Send variables',
                    'onClick' => 'IPS_RequestAction($id, "SendVariables", "");',
                ],
            ],
        ];

        $formActions[] = [
            'type'      => 'ExpansionPanel',
            'caption'   => 'Expert area',
            'expanded'  => false,
            'items'     => [
                $this->GetInstallVarProfilesFormItem(),
                [
                    'type'      => 'Button',
                    'caption'   => 'Adjust variable names',
                    'confirm'   => 'This adjusts the first part von the variable name acording to the retrived configuration',
                    'onClick'   => 'IPS_RequestAction($id, "AdjustVariablenames", "");',
                ],
            ],
        ];

        $formActions[] = [
            'type'      => 'ExpansionPanel',
            'caption'   => 'Test area',
            'expanded'  => false,
            'items'     => [
                [
                    'type'    => 'TestCenter',
                ],
            ]
        ];

        $formActions[] = $this->GetInformationFormAction();
        $formActions[] = $this->GetReferencesFormAction();

        return $formActions;
    }

    private function SetQueryInterval(int $sec = null)
    {
        if (is_null($sec)) {
            $sec = $this->ReadPropertyInteger('query_interval');
        }
        $this->MaintainTimer('QueryStatus', $sec * 1000);
    }

    private function SetSendInterval(int $sec = null)
    {
        if (is_null($sec)) {
            $sec = $this->ReadPropertyInteger('send_interval');
        }
        $this->MaintainTimer('SendVariables', $sec * 1000);
    }

    private function SaveTimezoneOffset($options)
    {
        $fnd = true;
        $tz = $this->GetArrayElem($options, 'tz', 0, $fnd);
        if ($fnd) {
            $tz_offs = ($tz - 48) / 4 * 3600;
            $this->SendDebug(__FUNCTION__, 'tz=' . $tz . ' => ' . $this->seconds2duration($tz_offs), 0);
            $this->WriteAttributeInteger('timezone_offset', $tz_offs);
        }
    }

    private function AdjustTimestamp($tstamp)
    {
        if ($tstamp > 0) {
            $tz_offs = $this->ReadAttributeInteger('timezone_offset');
            $tstamp -= $tz_offs;
        }
        return $tstamp;
    }

    private function SaveFlowVolume4Pulse($options)
    {
        $fnd = true;
        $fpr0 = $this->GetArrayElem($options, 'fpr0', 0, $fnd);
        if ($fnd) {
            $fpr1 = $this->GetArrayElem($options, 'fpr1', 0, $fnd);
            if ($fnd) {
                $fpr = (($fpr1 << 8) + $fpr0) / 100.0;
                $this->SendDebug(__FUNCTION__, 'fpr0=' . $fpr0 . ', fpr1=' . $fpr1 . ' => ' . $fpr . 'l/pulse', 0);
                $this->WriteAttributeInteger('pulse_volume', $fpr);
            }
        }
    }

    private function ConvertPulses2Volume($count)
    {
        $vol = $this->ReadAttributeInteger('pulse_volume');
        return $count * $vol;
    }

    private function QueryStatus()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $data = $this->do_HttpRequest('ja', []);
        if ($data == false) {
            return;
        }
        $jdata = @json_decode($data, true);
        if ($jdata === false) {
            return;
        }

        $zone_list = @json_decode($this->ReadPropertyString('zone_list'), true);
        if ($zone_list === false) {
            $zone_list = [];
        }
        $sensor_list = @json_decode($this->ReadPropertyString('sensor_list'), true);
        if ($sensor_list === false) {
            $sensor_list = [];
        }
        $program_list = @json_decode($this->ReadPropertyString('program_list'), true);
        if ($program_list === false) {
            $program_list = [];
        }

        $now = time();

        $this->SendDebug(__FUNCTION__, 'jdata=' . print_r($jdata, true), 0);
        $settings = $jdata['settings'];
        $this->SendDebug(__FUNCTION__, 'settings=' . print_r($settings, true), 0);
        $options = $jdata['options'];
        $this->SendDebug(__FUNCTION__, 'options=' . print_r($options, true), 0);
        $stations = $jdata['stations'];
        $this->SendDebug(__FUNCTION__, 'stations=' . print_r($stations, true), 0);
        $programs = $jdata['programs'];
        $this->SendDebug(__FUNCTION__, 'programs=' . print_r($programs, true), 0);

        $this->SaveTimezoneOffset($options);
        $this->SaveFlowVolume4Pulse($options);

        $fnd = true;

        $en = $this->GetArrayElem($jdata, 'settings.en', 0, $fnd);
        if ($fnd) {
            $i = $en ? self::$CONTROLLER_STATE_ENABLED : self::$CONTROLLER_STATE_DISABLED;
            $this->SendDebug(__FUNCTION__, '... ControllerState (settings.en)=' . $en . ' => ' . $i, 0);
            $this->SetValue('ControllerState', $i);
        }
        /*
            Change Controller Variables [Keyword /cv]
                /cv?pw=xxx&en=x
                Parameters:
                    ● en: Operation enable. Binary value.
         */

        $wl = $this->GetArrayElem($jdata, 'options.wl', 0, $fnd);
        if ($fnd) {
            $this->SendDebug(__FUNCTION__, '... WateringLevel (options.wl)=' . $wl, 0);
            $this->SetValue('WateringLevel', $wl);
        }
        /*
            Change Options [Keyword /co]
                /co?pw=xxx&wl=x
                Parameters:
                    ● wl: Waterlevel (i.e. % Watering). Acceptable range is 0 to 250.
         */

        $rdst = $this->GetArrayElem($jdata, 'settings.rdst', 0, $fnd);
        if ($fnd) {
            $this->SendDebug(__FUNCTION__, '... RainDelayUntil (settings.rdst)=' . $rdst, 0);
            $this->SetValue('RainDelayUntil', $rdst);
        }
        /*
            Change Controller Variables [Keyword /cv]
                /cv?pw=xxx&rd=x
                Parameters:
                    ● rd: Set rain delay time (in hours). Range is 0 to 32767. A value of 0 turns off rain delay.
         */

        $devt = $this->GetArrayElem($jdata, 'settings.devt', 0, $fnd);
        if ($fnd) {
            $devt_gm = $this->AdjustTimestamp($devt);
            $this->SendDebug(__FUNCTION__, '... DeviceTime (settings.devt)=' . $devt . ' => ' . $devt_gm, 0);
            $this->SetValue('DeviceTime', $devt_gm);
        }

        $rssi = $this->GetArrayElem($jdata, 'settings.RSSI', 0, $fnd);
        if ($fnd) {
            $this->SendDebug(__FUNCTION__, '... WifiStrength (settings.RSSI)=' . $devt, 0);
            $this->SetValue('WifiStrength', $rssi);
        }

        $lswc = $this->GetArrayElem($jdata, 'settings.lswc', 0, $fnd);
        if ($fnd) {
            $lswc_gm = $this->AdjustTimestamp($lswc);
            $this->SendDebug(__FUNCTION__, '... WeatherQueryTstamp (settings.lswc)=' . $lswc . ' => ' . $lswc_gm, 0);
            $this->SetValue('WeatherQueryTstamp', $lswc_gm);
        }

        $wterr = $this->GetArrayElem($jdata, 'settings.wterr', 0, $fnd);
        if ($fnd) {
            $this->SendDebug(__FUNCTION__, '... WeatherQueryStatus (settings.wterr)=' . $wterr, 0);
            $this->SetValue('WeatherQueryStatus', $wterr);
        }

        $lupt = $this->GetArrayElem($jdata, 'settings.lupt', 0, $fnd);
        if ($fnd) {
            $lupt_gm = $this->AdjustTimestamp($lupt);
            $this->SendDebug(__FUNCTION__, '... LastRebootTstamp (settings.lupt)=' . $lupt . ' => ' . $lupt_gm, 0);
            $this->SetValue('LastRebootTstamp', $lupt_gm);
        }

        $lrbtc = $this->GetArrayElem($jdata, 'settings.lrbtc', 0, $fnd);
        if ($fnd) {
            $this->SendDebug(__FUNCTION__, '... LastRebootCause (settings.lrbtc)=' . $lrbtc, 0);
            $this->SetValue('LastRebootCause', $lrbtc);
        }

        $curr = $this->GetArrayElem($jdata, 'settings.curr', 0, $fnd);
        if ($fnd) {
            $this->SendDebug(__FUNCTION__, '... CurrentDraw (settings.curr)=' . $curr, 0);
            $this->SetValue('CurrentDraw', $curr);
        }

        for ($i = 0; $i < self::$MAX_INT_SENSORS; $i++) {
            for ($n = 0; $n < count($sensor_list); $n++) {
                if ($sensor_list[$n]['no'] == $i) {
                    break;
                }
            }
            if ($n == count($sensor_list)) {
                continue;
            }
            $sensor_entry = $sensor_list[$n];

            $use = (bool) $this->GetArrayElem($sensor_entry, 'use', false);
            if ($use === false) {
                continue;
            }

            $post = '_' . ($i + 1);
            $sni = $i + 1;

            $snt = $this->GetArrayElem($sensor_entry, 'type', self::$SENSOR_TYPE_NONE);
            if (in_array($snt, [self::$SENSOR_TYPE_RAIN, self::$SENSOR_TYPE_SOIL])) {
                $sn = $this->GetArrayElem($jdata, 'settings.sn' . $sni, 0, $fnd);
                if ($fnd) {
                    $this->SendDebug(__FUNCTION__, '... SensorState' . $post . ' (settings.sn' . $sni . ')=' . $sn, 0);
                    $this->SetValue('SensorState' . $post, $sn);
                }
            }
            if ($snt == self::$SENSOR_TYPE_FLOW) {
                $flwrt = $this->GetArrayElem($jdata, 'settings.flwrt', 30);
                $flcrt = $this->GetArrayElem($jdata, 'settings.flcrt', 0, $fnd);
                if ($fnd) {
                    $flow_rate = $this->ConvertPulses2Volume($flcrt) / ($flwrt / 60);
                    $this->SendDebug(__FUNCTION__, '... WaterFlowrate (settings.flwrt)=' . $flwrt . '/(settings.flcrt)=' . $flcrt . ' => ' . $flow_rate, 0);
                    $this->SetValue('WaterFlowrate', $flow_rate);
                }
            }
        }

        /*
            Last run record, which stores the [station index, program index, duration, end time] of the last run station.
         */
        $lrun = (array) $this->GetArrayElem($jdata, 'settings.lrun', [], $fnd);
        if ($fnd) {
            $this->SendDebug(__FUNCTION__, '... (settings.lrun)=' . print_r($lrun, true), 0);
            $sid = $lrun[0];
            $pid = $lrun[1];
            $dur = $lrun[2];
            $end = $this->AdjustTimestamp($lrun[3]);
            $this->SendDebug(__FUNCTION__, '....... sid=' . $sid . ', pid=' . $pid . ', dur=' . $dur . ', end=' . ($end ? date('d.m.y H:i:s', $end) : '-'), 0);
        }

        /*
            zone disable
         */
        $stn_dis = (array) $this->GetArrayElem($jdata, 'stations.stn_dis', [], $fnd);
        if ($fnd) {
            $this->SendDebug(__FUNCTION__, '... (stations.stn_dis)=' . print_r($stn_dis, true), 0);
            for ($sid = 0; $sid < count($stn_dis) * 8; $sid++) {
                if ($this->idx_in_bytes($sid, $stn_dis)) {
                    $this->SendDebug(__FUNCTION__, '....... sid=' . $sid . ', disabled', 0);
                }
            }
        }

        $ignore_rain = $this->GetArrayElem($jdata, 'stations.ignore_rain', [], $fnd);
        if ($fnd) {
            $this->SendDebug(__FUNCTION__, '... (stations.ignore_rain)=' . print_r($ignore_rain, true), 0);
            for ($sid = 0; $sid < count($ignore_rain) * 8; $sid++) {
                if ($this->idx_in_bytes($sid, $ignore_rain)) {
                    $this->SendDebug(__FUNCTION__, '....... sid=' . $sid . ', ignore rain', 0);
                }
            }
        }

        $ignore_sn1 = $this->GetArrayElem($jdata, 'stations.ignore_sn1', [], $fnd);
        if ($fnd) {
            $this->SendDebug(__FUNCTION__, '... (stations.ignore_sn1)=' . print_r($ignore_sn1, true), 0);
            for ($sid = 0; $sid < count($ignore_sn1) * 8; $sid++) {
                if ($this->idx_in_bytes($sid, $ignore_sn1)) {
                    $this->SendDebug(__FUNCTION__, '....... sid=' . $sid . ', ignore sensor 1', 0);
                }
            }
        }

        $ignore_sn2 = $this->GetArrayElem($jdata, 'stations.ignore_sn2', [], $fnd);
        if ($fnd) {
            $this->SendDebug(__FUNCTION__, '... (stations.ignore_sn2)=' . print_r($ignore_sn2, true), 0);
            for ($sid = 0; $sid < count($ignore_sn2) * 8; $sid++) {
                if ($this->idx_in_bytes($sid, $ignore_sn2)) {
                    $this->SendDebug(__FUNCTION__, '....... sid=' . $sid . ', ignore sensor 2', 0);
                }
            }
        }

        /*
            Station status bits. Each byte in this array corresponds to an 8-station board and represents the bit field (LSB).
            For example, 1 means the 1st station on the board is open, 192 means the 7th and 8th stations are open.
         */
        $sbits = (array) $this->GetArrayElem($jdata, 'settings.sbits', [], $fnd);
        if ($fnd) {
            $this->SendDebug(__FUNCTION__, '... (settings.sbits)=' . print_r($sbits, true), 0);
            for ($sid = 0; $sid < count($sbits) * 8; $sid++) {
                if ($this->idx_in_bytes($sid, $sbits)) {
                    $this->SendDebug(__FUNCTION__, '....... sid=' . $sid . ', active', 0);
                }
            }
        }

        /*
            Program status data: each element is a 4-field array that stores the [pid,rem,start,gid] of a station,
            where
                pid is the program index (0 means none),
                rem is the remaining water time (in seconds),
                start is the start time, and
                gid is the (sequential) group id of the station.
            If a station is not running (sbit is 0) but has a non-zero pid, that means the station is in the queue waiting to run.
         */
        $ps = (array) $this->GetArrayElem($jdata, 'settings.ps', [], $fnd);
        if ($fnd) {
            $this->SendDebug(__FUNCTION__, '... (settings.ps)=' . print_r($ps, true), 0);
            for ($sid = 0; $sid < count($ps); $sid++) {
                $pid = $ps[$sid][0];
                $rem = $ps[$sid][1];
                $start = $this->AdjustTimestamp($ps[$sid][2]);
                $gid = $ps[$sid][3];
                if ($pid != 0 || $rem != 0 || $start != 0) {
                    $this->SendDebug(__FUNCTION__, '....... sid=' . $sid . ', pid=' . $pid . ', rem=' . $rem . 's, start=' . ($start ? date('d.m.y H:i:s', $start) : '-') . ', gid=' . $this->Group2String($gid), 0);
                }
            }
        }

        /*
            Strömungsüberwachungs-Grenzmenge in l/min (/100)
                cmd=cs, opt=f
         */
        $stn_fas = (array) $this->GetArrayElem($jdata, 'stations.stn_fas', [], $fnd);
        if ($fnd) {
            $this->SendDebug(__FUNCTION__, '... (stations.stn_fas)=' . print_r($ps, true), 0);
            for ($sid = 0; $sid < count($stn_fas); $sid++) {
                if ($stn_fas[$sid] > 0) {
                    $this->SendDebug(__FUNCTION__, '....... sid=' . $sid . ', stn_fas=' . ($stn_fas[$sid] / 100), 0);
                }
            }
        }

        /*
            Durchschnittliche Strömungsmenge in l/min (/100)
                cmd=cs, opt=a
         */
        $stn_favg = (array) $this->GetArrayElem($jdata, 'stations.stn_favg', [], $fnd);
        if ($fnd) {
            $this->SendDebug(__FUNCTION__, '... (stations.stn_favg)=' . print_r($ps, true), 0);
            for ($sid = 0; $sid < count($stn_favg); $sid++) {
                if ($stn_favg[$sid] > 0) {
                    $this->SendDebug(__FUNCTION__, '....... sid=' . $sid . ', stn_favg=' . ($stn_favg[$sid] / 100), 0);
                }
            }
        }

        $maxlen = $this->GetArrayElem($jdata, 'stations.maxlen', 0);
        for ($i = 0; $i < $maxlen; $i++) {
            for ($n = 0; $n < count($zone_list); $n++) {
                if ($zone_list[$n]['no'] == $i) {
                    break;
                }
            }
            if ($n == count($zone_list)) {
                continue;
            }
            $zone_entry = $zone_list[$n];

            $use = (bool) $this->GetArrayElem($zone_entry, 'use', false);
            if ($use === false) {
                continue;
            }

            $post = '_' . ($i + 1);

            $pid = $ps[$i][0];
            $rem = $ps[$i][1];
            $start = $this->AdjustTimestamp($ps[$i][2]);

            $nextStart = 0;
            $nextDur = 0;
            $curLeft = 0;

            if ($this->idx_in_bytes($i, $stn_dis)) {
                $state = self::$ZONE_STATE_DISABLED;
            } elseif ($ps[$i][0] != 0) {
                if ($this->idx_in_bytes($i, $sbits)) {
                    $state = self::$ZONE_STATE_WATERING;
                    $curLeft = $rem;
                } else {
                    $state = self::$ZONE_STATE_QUEUED;
                    $nextStart = $start;
                    $nextDur = $rem;
                }
            } else {
                $state = self::$ZONE_STATE_READY;
            }

            $this->SendDebug(__FUNCTION__, '... ZoneState' . $post . ' => ' . $state, 0);
            $this->SetValue('ZoneState' . $post, $state);

            $this->SendDebug(__FUNCTION__, '... ZoneTimeLeft' . $post . ' => ' . $curLeft, 0);
            $this->SetValue('ZoneTimeLeft' . $post, $curLeft);

            $this->SendDebug(__FUNCTION__, '... ZoneNextRun' . $post . ' => ' . $nextStart, 0);
            $this->SetValue('ZoneNextRun' . $post, $nextStart);

            $this->SendDebug(__FUNCTION__, '... ZoneNextDuration' . $post . ' => ' . $nextDur, 0);
            $this->SetValue('ZoneNextDuration' . $post, $nextDur);

            $sid = $lrun[0];
            $dur = $lrun[2];
            $end = $this->AdjustTimestamp($lrun[3]);
            if ($sid == $i && $dur != 0 && $end != 0) {
                $start = $end - $dur;
                $this->SendDebug(__FUNCTION__, '... ZoneLastRun' . $post . ' => ' . date('d.m.y H:i:s', $start), 0);
                $this->SetValue('ZoneLastRun' . $post, $start);
                $this->SendDebug(__FUNCTION__, '... ZoneLastDuration' . $post . ' => ' . $dur, 0);
                $this->SetValue('ZoneLastDuration' . $post, $dur);
            }
            /*
            stations.stn_fas=Strömungsüberwachungs-Grenzmenge in l/min (/100)
            stations.stn_favg=Durchschnittliche Strömungsmenge in l/min (/100)
             */

            /*
                12. Manual Station Run (previously manual override) [Keyword /cm]
                    /cm?pw=xxx&sid=xx&en=x&t=xxx&ssta=xxx
                    Parameters:
                        ● sid: Stationindex(starting from 0)
                        ● en: Enablebit(1: open the selected station; 0: close the selected station).
                        ● t: Timer(inseconds). Acceptable range is 0 to 64800 (18 hours)
                        ● ssta: shift remaining stations in the same sequential group (0: do not shift remaining stations; 1: shift remaining stations forward). Only if en=0)
             */
        }

        $nprogs = $this->GetArrayElem($jdata, 'programs.nprogs', 0);
        for ($i = 0; $i < $nprogs; $i++) {
            for ($n = 0; $n < count($program_list); $n++) {
                if ($program_list[$n]['no'] == $i) {
                    break;
                }
            }
            if ($n == count($program_list)) {
                continue;
            }
            $program_entry = $program_list[$n];

            $use = (bool) $this->GetArrayElem($program_entry, 'use', false);
            if ($use === false) {
                continue;
            }

            $post = '_' . ($i + 1);

            /*
                Manually Start a Program [Keyword /mp]
                    /mp?pw=xxx&pid=xx&uwt=x
                    Parameters:
                    ● pid: programindex(startingfrom0asthefirstprogram)
                    ● uwt: use weather (i.e. applying current water level / percentage). Binary value.
             */
        }

        /*
            Pause Queue [Keyword /pq]
                /pq?pw=x&dur=xxx
                This command triggers (toggles) a pause with the specified duration (in units of seconds).
                Calling it first with a non-zero duration will start the pause; calling it again (regardless of duration value) will cancel the pause and resume station runs (i.e. it's a toggle).
         */
        /*
            Change Controller Variables [Keyword /cv]
                /cv?pw=xxx&rsn=x
                Parameters:
                    ● rsn: Reset all stations (including those waiting to run). The value doesn’t matter: action is triggered if parameter appears
            => stop alle stationen
         */

        $this->SetValue('LastUpdate', $now);

        $zone_infos = [];

        $maxlen = $this->GetArrayElem($jdata, 'stations.maxlen', 0);
        $snames = (array) $this->GetArrayElem($jdata, 'stations.snames', '');
        $ignore_rain = (array) $this->GetArrayElem($jdata, 'stations.ignore_rain', []);
        $ignore_sn1 = (array) $this->GetArrayElem($jdata, 'stations.ignore_sn1', []);
        $ignore_sn2 = (array) $this->GetArrayElem($jdata, 'stations.ignore_sn2', []);
        $stn_dis = (array) $this->GetArrayElem($jdata, 'stations.stn_dis', []);
        $stn_grp = (array) $this->GetArrayElem($jdata, 'stations.stn_grp', []);
        $stn_spe = (array) $this->GetArrayElem($jdata, 'stations.stn_spe', []);
        $mas = $this->GetArrayElem($jdata, 'options.mas', 0);
        $mas2 = $this->GetArrayElem($jdata, 'options.mas2', 0);
        $stn_fas = (array) $this->GetArrayElem($jdata, 'stations.stn_fas', []);
        $stn_favg = (array) $this->GetArrayElem($jdata, 'stations.stn_favg', []);

        $nprogs = $this->GetArrayElem($jdata, 'programs.nprogs', 0);

        for ($i = 0; $i < $maxlen; $i++) {
            for ($n = 0; $n < count($zone_list); $n++) {
                if ($zone_list[$n]['no'] == $i) {
                    break;
                }
            }
            if ($n == count($zone_list)) {
                continue;
            }
            $zone_entry = $zone_list[$n];

            $use = (bool) $this->GetArrayElem($zone_entry, 'use', false);
            if ($use === false) {
                continue;
            }

            if ($i == ($mas - 1)) {
                $master = 1;
            } elseif ($i == ($mas2 - 1)) {
                $master = 2;
            } else {
                $master = 0;
            }

            $prV = [];
            for ($j = 0; $j < $nprogs; $j++) {
                for ($n = 0; $n < count($program_list); $n++) {
                    if ($program_list[$n]['no'] == $j) {
                        break;
                    }
                }
                if ($n == count($program_list)) {
                    continue;
                }
                $program_entry = $program_list[$n];

                $use = (bool) $this->GetArrayElem($program_entry, 'use', false);
                if ($use === false) {
                    continue;
                }

                $duration = (array) $this->GetArrayElem($jdata, 'programs.pd.' . $j . '.4', []);
                if ($duration[$i] == 0) {
                    continue;
                }

                $flag = $this->GetArrayElem($jdata, 'programs.pd.' . $j . '.0', '');
                $start = (array) $this->GetArrayElem($jdata, 'programs.pd.' . $j . '.3', []);
                $name = $this->GetArrayElem($jdata, 'programs.pd.' . $j . '.5', '');

                $repV = [];
                if ($this->bit_test($flag, 6)) {
                    for ($n = 0; $n < count($start); $n++) {
                        $min = $start[$n];
                        if ($min != -1) {
                            $repV[] = sprintf('%02d:%02d', ($min / 60), ($min % 60));
                        }
                    }
                } else {
                    $min = $start[0];
                    $repV[] = sprintf('%02d:%02d', ($min / 60), ($min % 60));
                    for ($n = 0; $n < $start[1]; $n++) {
                        $min += $start[2];
                        $repV[] = sprintf('%02d:%02d', ($min / 60), ($min % 60));
                    }
                }

                $prV[] = $name . '[' . $this->seconds2duration($duration[$i]) . ' @ ' . implode('/', $repV) . ']';
            }

            $info = implode(', ', $prV);

            $zone_infos[] = [
                'sid'                 => $i + 1,
                'name'                => $snames[$i],
                'group'               => $this->Group2String($stn_grp[$i]),
                'disabled'            => $this->idx_in_bytes($i, $stn_dis),
                'ignore_rain'         => $this->idx_in_bytes($i, $ignore_rain),
                'ignore_sn1'          => $this->idx_in_bytes($i, $ignore_sn1),
                'ignore_sn2'          => $this->idx_in_bytes($i, $ignore_sn2),
                'is_special'          => $this->idx_in_bytes($i, $stn_spe),
                'master'              => $master,
                'stn_fas'             => $stn_fas[$i],
                'info'                => $info,
            ];
        }
        $this->SendDebug(__FUNCTION__, 'zone_infos=' . print_r($zone_infos, true), 0);
        $this->WriteAttributeString('zone_infos', json_encode($zone_infos));

        $associations = [
            [
                'Value' => 0,
                'Name'  => '-'
            ],
        ];
        foreach ($zone_infos as $info) {
            $associations[] = [
                'Value' => $info['sid'],
                'Name'  => $info['name'],
            ];
        }
        $this->UpdateVarProfileAssociations($this->VarProf_Zones, $associations);

        $program_infos = [];
        for ($i = 0; $i < $nprogs; $i++) {
            for ($n = 0; $n < count($program_list); $n++) {
                if ($program_list[$n]['no'] == $i) {
                    break;
                }
            }
            if ($n == count($program_list)) {
                continue;
            }
            $program_entry = $program_list[$n];

            $use = (bool) $this->GetArrayElem($program_entry, 'use', false);
            if ($use === false) {
                continue;
            }

            $flag = $this->GetArrayElem($jdata, 'programs.pd.' . $i . '.0', '');
            $days0 = $this->GetArrayElem($jdata, 'programs.pd.' . $i . '.1', '');
            $days1 = $this->GetArrayElem($jdata, 'programs.pd.' . $i . '.2', '');
            $start = (array) $this->GetArrayElem($jdata, 'programs.pd.' . $i . '.3', []);
            $duration = (array) $this->GetArrayElem($jdata, 'programs.pd.' . $i . '.4', []);
            $name = $this->GetArrayElem($jdata, 'programs.pd.' . $i . '.5', '');
            $daterange = (array) $this->GetArrayElem($jdata, 'programs.pd.' . $i . '.6', []);

            $repV = [];
            if ($this->bit_test($flag, 6)) {
                for ($n = 0; $n < count($start); $n++) {
                    $min = $start[$n];
                    if ($min != -1) {
                        $repV[] = sprintf('%02d:%02d', ($min / 60), ($min % 60));
                    }
                }
            } else {
                $min = $start[0];
                $repV[] = sprintf('%02d:%02d', ($min / 60), ($min % 60));
                for ($n = 0; $n < $start[1]; $n++) {
                    $min += $start[2];
                    $repV[] = sprintf('%02d:%02d', ($min / 60), ($min % 60));
                }
            }

            $znV = [];
            for ($j = 0; $j < $maxlen; $j++) {
                for ($n = 0; $n < count($zone_list); $n++) {
                    if ($zone_list[$n]['no'] == $j) {
                        break;
                    }
                }
                if ($n == count($zone_list)) {
                    continue;
                }
                $zone_entry = $zone_list[$n];

                $use = (bool) $this->GetArrayElem($zone_entry, 'use', false);
                if ($use === false) {
                    continue;
                }

                if ($duration[$j] == 0) {
                    continue;
                }
                $znV[] = $snames[$j] . '[' . $this->seconds2duration($duration[$j]) . ']';
            }

            $info = $this->TranslateFormat('Start at {$rep} with zone(s) {$zn}', ['{$rep}' => implode('/', $repV), '{$zn}' => implode(', ', $znV)]);

            $program_infos[] = [
                'pid'                 => $i + 1,
                'name'                => $name,
                'enabled'             => $this->bit_test($flag, 0),
                'weather_adjustment'  => $this->bit_test($flag, 1),
                'flag'                => $flag,
                'days0'               => $days0,
                'days1'               => $days1,
                'start'               => $start,
                'duration'            => $duration,
                'daterange'           => $daterange,
                'total_duration'      => array_sum($duration),
                'info'                => $info,
            ];
        }
        $this->SendDebug(__FUNCTION__, 'program_infos=' . print_r($program_infos, true), 0);
        $this->WriteAttributeString('program_infos', json_encode($program_infos));

        $associations = [
            [
                'Value' => 0,
                'Name'  => '-'
            ],
        ];
        foreach ($program_infos as $info) {
            $associations[] = [
                'Value' => $info['pid'],
                'Name'  => $info['name'],
            ];
        }
        $this->UpdateVarProfileAssociations($this->VarProf_Programs, $associations);

        $this->SetZoneSelection($this->GetValue('ZoneSelection'));
        $this->SetProgramSelection($this->GetValue('ProgramSelection'));

        $this->SetQueryInterval();
    }

    private function idx_in_bytes($idx, $val)
    {
        $byte = floor($idx / 8);
        if ($byte > count($val)) {
            return false;
        }
        $bit = $idx % 8;
        return $this->bit_test($val[$byte], $bit);
    }

    private function RetriveConfiguration()
    {
        $data = $this->do_HttpRequest('ja', []);
        if ($data === false) {
            return;
        }
        $ja_data = json_decode($data, true);
        $this->SendDebug(__FUNCTION__, 'stations=' . print_r($ja_data['stations'], true), 0);
        $this->SendDebug(__FUNCTION__, 'options=' . print_r($ja_data['options'], true), 0);
        $this->SendDebug(__FUNCTION__, 'programs=' . print_r($ja_data['programs'], true), 0);

        $data = $this->do_HttpRequest('je', []);
        $special_stations = json_decode($data, true);
        $this->SendDebug(__FUNCTION__, 'special_stations=' . print_r($special_stations, true), 0);

        $zone_list = [];
        $maxlen = $this->GetArrayElem($ja_data, 'stations.maxlen', 0);
        $ignore_rain = (array) $this->GetArrayElem($ja_data, 'stations.ignore_rain', []);
        $ignore_sn1 = (array) $this->GetArrayElem($ja_data, 'stations.ignore_sn1', []);
        $ignore_sn2 = (array) $this->GetArrayElem($ja_data, 'stations.ignore_sn2', []);
        $stn_dis = (array) $this->GetArrayElem($ja_data, 'stations.stn_dis', []);
        $stn_spe = (array) $this->GetArrayElem($ja_data, 'stations.stn_spe', []);
        $mas = $this->GetArrayElem($ja_data, 'options.mas', 0);
        $mas2 = $this->GetArrayElem($ja_data, 'options.mas2', 0);
        for ($idx = 0; $idx < $maxlen; $idx++) {
            /*
            if ($this->idx_in_bytes($idx, $stn_dis)) {
                continue;
            }
             */
            $sname = $this->GetArrayElem($ja_data, 'stations.snames.' . $idx, '');
            $stn_grp = $this->GetArrayElem($ja_data, 'stations.stn_grp.' . $idx, 0);
            $infos = [];

            if ($this->idx_in_bytes($idx, $stn_dis)) {
                $infos[] = $this->Translate('Disabled');
            }
            if ($idx == ($mas - 1)) {
                $infos[] = $this->Translate('Master valve') . ' 1';
            }
            if ($idx == ($mas2 - 1)) {
                $infos[] = $this->Translate('Master valve') . ' 2';
            }

            if ($this->idx_in_bytes($idx, $ignore_rain)) {
                $infos[] = $this->Translate('ignore rain delay');
            }
            if ($this->idx_in_bytes($idx, $ignore_sn1)) {
                $snt = $this->GetArrayElem($ja_data, 'options.sn1t', 0);
                if ($snt == self::$SENSOR_TYPE_FLOW) {
                    $infos[] = $this->Translate('no flow measuring');
                } else {
                    $infos[] = $this->Translate('ignore sensor 1');
                }
            }
            if ($this->idx_in_bytes($idx, $ignore_sn2)) {
                $snt = $this->GetArrayElem($ja_data, 'options.sn2t', 0);
                if ($snt == self::$SENSOR_TYPE_FLOW) {
                    $infos[] = $this->Translate('no flow measuring');
                } else {
                    $infos[] = $this->Translate('ignore sensor 2');
                }
            }
            if ($this->idx_in_bytes($idx, $stn_spe)) {
                $st = $this->GetArrayElem($special_stations, $idx . '.st', 0);
                switch ($st) {
                    case 0: // local
                        $interface = sprintf('S%02d', $idx + 1);
                        break;
                    case 1: // RF (radio frequency) station
                        $interface = $this->Translate('RF');
                        break;
                    case 2: // remote station (IP)
                        $sd = $this->GetArrayElem($special_stations, $idx . '.sd', 0);
                        $ip = strval(hexdec(substr($sd, 0, 2))) . '.' . strval(hexdec(substr($sd, 2, 2))) . '.' . strval(hexdec(substr($sd, 4, 2))) . '.' . strval(hexdec(substr($sd, 6, 2)));
                        $port = hexdec(substr($sd, 8, 4));
                        $sid = hexdec(substr($sd, 12, 2));
                        $interface = $this->Translate('remote') . sprintf(' [S%02d]', $idx + 1);
                        break;
                    case 3: // GPIO station
                        $interface = $this->Translate('GPIO');
                        break;
                    case 4: // HTTP station
                        $interface = $this->Translate('HTTP');
                        break;
                    case 5: // HTTPS stations
                        $interface = $this->Translate('HTTPS');
                        break;
                    case 6: // remote station (OTC)
                        $interface = $this->Translate('remote (OTC)');
                        break;
                    default:
                        break;
                }
            } else {
                $interface = sprintf('S%02d', $idx + 1);
            }

            $zone_list[] = [
                'no'        => $idx,
                'name'      => $sname,
                'group'     => $this->Group2String($stn_grp),
                'interface' => $interface,
                'info'      => implode(', ', $infos),
                'use'       => true,
            ];
        }

        if ($zone_list != @json_decode($this->ReadPropertyString('zone_list'), true)) {
            $this->SendDebug(__FUNCTION__, 'update zone_list=' . print_r($zone_list, true), 0);
            $this->UpdateFormField('zone_list', 'values', json_encode($zone_list));
            $this->UpdateFormField('zone_list', 'rowCount', count($zone_list) > 0 ? count($zone_list) : 1);
        } else {
            $this->SendDebug(__FUNCTION__, 'unchanges zone_list=' . print_r($zone_list, true), 0);
        }

        $sensor_list = [];
        for ($idx = 0; $idx <= 1; $idx++) {
            $sni = $idx + 1;
            $snt = $this->GetArrayElem($ja_data, 'options.sn' . $sni . 't', 0);
            switch ($snt) {
                case self::$SENSOR_TYPE_RAIN:
                    $sno = $this->GetArrayElem($ja_data, 'options.sn' . $sni . 'o', 0);
                    $sensor_list[] = [
                        'no'   => $idx,
                        'type' => $snt,
                        'name' => $this->SensorType2String($snt),
                        'info' => $this->Translate('Contact variant') . ': ' . $this->SensorType2String($sno),
                        'use'  => true,
                    ];
                    break;
                case self::$SENSOR_TYPE_FLOW:
                    $fpr0 = $this->GetArrayElem($ja_data, 'options.fpr0', 0);
                    $fpr1 = $this->GetArrayElem($ja_data, 'options.fpr1', 0);
                    $fpr = (($fpr1 << 8) + $fpr0) / 100.0;
                    $sensor_list[] = [
                        'no'   => $idx,
                        'type' => $snt,
                        'name' => $this->SensorType2String($snt),
                        'info' => $this->TranslateFormat('Resolution: {$fpr} l/pulse', ['{$fpr}' => $fpr]),
                        'use'  => true,
                    ];
                    break;
                case self::$SENSOR_TYPE_SOIL:
                    $sno = $this->GetArrayElem($ja_data, 'options.sn' . $sni . 'o', 0);
                    $sensor_list[] = [
                        'no'   => $idx,
                        'type' => $snt,
                        'name' => $this->SensorType2String($snt),
                        'use'  => true,
                        'info' => $this->Translate($sno ? 'normally open' : 'normally closed'),
                    ];
                    break;
                default:
                    break;
            }
        }

        if ($sensor_list != @json_decode($this->ReadPropertyString('sensor_list'), true)) {
            $this->SendDebug(__FUNCTION__, 'update sensor_list=' . print_r($sensor_list, true), 0);
            $this->UpdateFormField('sensor_list', 'values', json_encode($sensor_list));
            $this->UpdateFormField('sensor_list', 'rowCount', count($sensor_list) > 0 ? count($sensor_list) : 1);
        } else {
            $this->SendDebug(__FUNCTION__, 'unchanges sensor_list=' . print_r($sensor_list, true), 0);
        }

        $program_list = [];
        $nprogs = $this->GetArrayElem($ja_data, 'programs.nprogs', 0);
        for ($idx = 0; $idx < $nprogs; $idx++) {
            $flag = $this->GetArrayElem($ja_data, 'programs.pd.' . $idx . '.0', '');
            $days0 = $this->GetArrayElem($ja_data, 'programs.pd.' . $idx . '.1', '');
            $days1 = $this->GetArrayElem($ja_data, 'programs.pd.' . $idx . '.2', '');
            $start = $this->GetArrayElem($ja_data, 'programs.pd.' . $idx . '.3', '');
            $duration = $this->GetArrayElem($ja_data, 'programs.pd.' . $idx . '.4', '');
            $name = $this->GetArrayElem($ja_data, 'programs.pd.' . $idx . '.5', '');
            $daterange = $this->GetArrayElem($ja_data, 'programs.pd.' . $idx . '.6', '');

            $infos = [];
            if ($this->bit_test($flag, 0) == false) {
                $infos[] = $this->Translate('Disabled');
            }
            if ($this->bit_test($flag, 1)) {
                $infos[] = $this->Translate('Weather adjustment');
            }
            $total_duration = array_sum($duration);
            $infos[] = $this->TranslateFormat('Total duration is {$total_duration}m', ['{$total_duration}' => $this->seconds2duration($total_duration)]);

            $program_list[] = [
                'no'   => $idx,
                'name' => $name,
                'info' => implode(', ', $infos),
                'use'  => true,
            ];
        }

        if ($program_list != @json_decode($this->ReadPropertyString('program_list'), true)) {
            $this->SendDebug(__FUNCTION__, 'update program_list=' . print_r($program_list, true), 0);
            $this->UpdateFormField('program_list', 'values', json_encode($program_list));
            $this->UpdateFormField('program_list', 'rowCount', count($program_list) > 0 ? count($program_list) : 1);
        } else {
            $this->SendDebug(__FUNCTION__, 'unchanged program_list=' . print_r($program_list, true), 0);
        }
    }

    public function ReceiveData($data)
    {
        $this->SendDebug(__FUNCTION__, 'data=' . $data, 0);

        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $jdata = json_decode($data, true);
        $topic = isset($jdata['Topic']) ? $jdata['Topic'] : '';
        $mqtt_topic = $this->ReadPropertyString('mqtt_topic');
        $topic = substr($topic, strlen($mqtt_topic) + 1);
        $payload = isset($jdata['Payload']) ? $jdata['Payload'] : '';
        switch ($topic) {
            case 'availability':
                break;
            default:
                $payload = @json_decode($payload, true);
                break;
        }

        $this->SendDebug(__FUNCTION__, 'topic=' . $topic . ', payload=' . print_r($payload, true), 0);

        $a = explode('/', $topic);
        $c = array_shift($a);
        if ($c == 'station') {
            $sid = $a[0];

            $zone_list = @json_decode($this->ReadPropertyString('zone_list'), true);
            for ($n = 0; $n < count($zone_list); $n++) {
                if ($zone_list[$n]['no'] == $sid) {
                    break;
                }
            }
            if ($n == count($zone_list)) {
                $use = false;
            } else {
                $zone_entry = $zone_list[$n];
                $use = (bool) $this->GetArrayElem($zone_entry, 'use', false);
            }

            if ($use) {
                $post = '_' . ($sid + 1);

                if (count($a) == 1) {
                    $fnd = true;
                    $state = $this->GetArrayElem($payload, 'state', 0, $fnd);
                    if ($fnd) {
                        $st = $state ? self::$ZONE_STATE_WATERING : self::$ZONE_STATE_READY;
                        $this->SendDebug(__FUNCTION__, '... ZoneState' . $post . ' => ' . $st, 0);
                        $this->SetValue('ZoneState' . $post, $st);
                    }
                    $duration = $this->GetArrayElem($payload, 'duration', 0, $fnd);
                    if ($fnd) {
                        if ($state) {
                            $this->SendDebug(__FUNCTION__, '... ZoneTimeLeft' . $post . ' => ' . $duration, 0);
                            $this->SetValue('ZoneTimeLeft' . $post, $duration);
                        } else {
                            $lastRun = time() - $duration;
                            $this->SendDebug(__FUNCTION__, '... ZoneLastRun' . $post . ' => ' . date('d.m.y H:i:s', $lastRun), 0);
                            $this->SetValue('ZoneLastRun' . $post, $lastRun);

                            $this->SendDebug(__FUNCTION__, '... ZoneLastDuration' . $post . ' => ' . $duration, 0);
                            $this->SetValue('ZoneLastDuration' . $post, $duration);

                            $this->SetValue('ZoneTimeLeft' . $post, 0);
                        }
                    }
                    $flow = $this->GetArrayElem($payload, 'flow', 0, $fnd);
                    if ($fnd) {
                        // usage oder flow_rate?
                    }
                }
            }

            if (count($a) == 3 && $a[1] == 'alert' && $a[2] == 'flow') {
            }
        }

        /*
        switch ($c) {
            availability
            system
            raindelay
            weather
            monitoring
            sensor/flow
            sensor1
            sensor2
            analogsensor
         */

        // 25.11.2024 09:40:02 | TXT | ReceiveData | topic=station/0, payload=Array<LF>(<LF>    [state] => 1<LF>    [duration] => 300<LF>)<LF>
        // 21.11.2024 09:35:02 | TXT | ReceiveData | topic=station/0, payload=Array<LF>(<LF>    [state] => 0<LF>    [duration] => 300<LF>    [flow] => 0<LF>)<LF>
        // 21.11.2024 09:40:02 | TXT | ReceiveData | topic=sensor/flow, payload=Array<LF>(<LF>    [count] => 0<LF>    [volume] => 0<LF>)<LF>

        $this->MaintainStatus(IS_ACTIVE);
    }

    protected function SendVariables()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $variables_mqtt_topic = $this->ReadPropertyString('variables_mqtt_topic');

        $variable_list = @json_decode($this->ReadPropertyString('variable_list'), true);
        if ($variable_list === false) {
            $variable_list = [];
        }
        $payload = [];
        foreach ($variable_list as $variable) {
            $varID = $variable['varID'];
            if (IPS_VariableExists($varID) == false) {
                continue;
            }
            $payload[$variable['mqtt_filter']] = GetValue($varID);
        }
        $r = $this->PublishToOpenSprinkler($variables_mqtt_topic, $payload);

        $this->SendDebug(__FUNCTION__, $this->PrintTimer('SendVariables'), 0);
    }

    protected function PublishToOpenSprinkler($topic, $payload)
    {
        $jdata = [
            'DataID'           => '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}',
            'PacketType'       => 3,
            'QualityOfService' => 0,
            'Retain'           => false,
            'Topic'            => $topic,
            'Payload'          => json_encode($payload),
        ];

        $data = json_encode($jdata);
        $r = $this->SendDataToParent($data);
        $this->SendDebug(__FUNCTION__, 'SendDataToParent(' . $data . ') => ' . $r, 0);
        return $r;
    }

    public function ForwardData($data)
    {
        $this->SendDebug(__FUNCTION__, 'data=' . $data, 0);

        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $jdata = @json_decode($data, true);
        $this->SendDebug(__FUNCTION__, 'jdata=' . print_r($jdata, true), 0);
    }

    private function LocalRequestAction($ident, $value)
    {
        $r = true;

        switch ($ident) {
            case 'QueryStatus':
                $this->QueryStatus();
                break;
            case 'SendVariables':
                $this->SendVariables();
                break;
            case 'RetriveConfiguration':
                $this->RetriveConfiguration();
                break;
            case 'AdjustVariablenames':
                $this->AdjustVariablenames();
                break;
            default:
                $r = false;
                break;
        }
        return $r;
    }

    public function RequestAction($ident, $value)
    {
        if ($this->LocalRequestAction($ident, $value)) {
            return;
        }
        if ($this->CommonRequestAction($ident, $value)) {
            return;
        }

        if ($this->GetStatus() == IS_INACTIVE) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $this->SendDebug(__FUNCTION__, 'ident=' . $ident . ', value=' . $value, 0);

        $_ident = explode('_', $ident);
        $ident_base = $_ident[0];
        $ident_extent = isset($_ident[1]) ? $_ident[1] : '';

        $r = false;
        switch ($ident_base) {
            case 'ZoneSelection':
                $this->SendDebug(__FUNCTION__, $ident . '=' . $value, 0);
                $r = $this->SetZoneSelection((int) $value);
                break;
            case 'ZoneDisabled':
                $this->SendDebug(__FUNCTION__, $ident . '=' . $value, 0);
                $r = $this->SetZoneDisabled((bool) $value);
                break;
            case 'ZoneIgnoreRain':
                $this->SendDebug(__FUNCTION__, $ident . '=' . $value, 0);
                $r = $this->SetZoneIgnoreRain((bool) $value);
                break;
            case 'ZoneIgnoreSensor1':
                $this->SendDebug(__FUNCTION__, $ident . '=' . $value, 0);
                $r = $this->SetZoneIgnoreSensor1((bool) $value);
                break;
            case 'ZoneIgnoreSensor2':
                $this->SendDebug(__FUNCTION__, $ident . '=' . $value, 0);
                $r = $this->SetZoneIgnoreSensor2((bool) $value);
                break;
            case 'ProgramSelection':
                $this->SendDebug(__FUNCTION__, $ident . '=' . $value, 0);
                $r = $this->SetProgramSelection((int) $value);
                break;
            case 'ProgramEnabled':
                $this->SendDebug(__FUNCTION__, $ident . '=' . $value, 0);
                $r = $this->SetProgramEnabled((bool) $value);
                break;
            case 'ProgramWeatherAdjust':
                $this->SendDebug(__FUNCTION__, $ident . '=' . $value, 0);
                $r = $this->SetProgramWeatherAdjust((bool) $value);
                break;
            default:
                $this->SendDebug(__FUNCTION__, 'invalid ident ' . $ident, 0);
                break;
        }
        if ($r) {
            $this->SetValue($ident, $value);
            switch ($ident_base) {
                case 'ZoneDisabled':
                case 'ZoneIgnoreRain':
                case 'ZoneIgnoreSensor1':
                case 'ZoneIgnoreSensor2':
                case 'ProgramEnabled':
                case 'ProgramWeatherAdjust':
                    $this->MaintainTimer('QueryStatus', 500);
                    break;
                default:
                    break;
            }
        }
    }

    private function build_url($url, $params)
    {
        $n = 0;
        if (is_array($params)) {
            foreach ($params as $param => $value) {
                $url .= ($n++ ? '&' : '?') . $param . '=' . rawurlencode(strval($value));
            }
        }
        return $url;
    }

    private function build_header($headerfields)
    {
        $header = [];
        foreach ($headerfields as $key => $value) {
            $header[] = $key . ': ' . $value;
        }
        return $header;
    }

    private function do_HttpRequest($cmd, $params)
    {
        $host = $this->ReadPropertyString('host');
        $use_https = $this->ReadPropertyBoolean('use_https');
        $port = $this->ReadPropertyInteger('port');
        $password = $this->ReadPropertyString('password');

        $url = ($use_https ? 'https://' : 'http://') . $host . ($port > 0 ? (':' . $port) : '') . '/' . $cmd;
        $url = $this->build_url($url, array_merge(['pw' => md5($password)], $params));

        $headerfields = [
            'Accept' => 'application/json',
        ];
        $header = $this->build_header($headerfields);

        $this->SendDebug(__FUNCTION__, 'url=' . $url, 0);

        $time_start = microtime(true);

        if ($header != '') {
            $this->SendDebug(__FUNCTION__, '    header=' . print_r($header, true), 0);
        }

        $curl_opts = [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPHEADER     => $header,
            CURLOPT_HEADER         => true,
            CURLINFO_HEADER_OUT    => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, $curl_opts);
        $response = curl_exec($ch);
        $cerrno = curl_errno($ch);
        $cerror = $cerrno ? curl_error($ch) : '';
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_info = curl_getinfo($ch);
        curl_close($ch);

        $duration = round(microtime(true) - $time_start, 2);
        $this->SendDebug(__FUNCTION__, ' => errno=' . $cerrno . ', httpcode=' . $httpcode . ', duration=' . $duration . 's', 0);
        $this->SendDebug(__FUNCTION__, ' => response=' . $response, 0);

        $statuscode = 0;
        $err = '';

        if ($cerrno) {
            $statuscode = self::$IS_SERVERERROR;
            $err = 'got curl-errno ' . $cerrno . ' (' . $cerror . ')';
        } else {
            $header_size = $curl_info['header_size'];
            $head = substr($response, 0, $header_size);
            $body = substr($response, $header_size);
            $this->SendDebug(__FUNCTION__, ' => head=' . $head, 0);
            $this->SendDebug(__FUNCTION__, ' => body=' . $body, 0);

            if ($httpcode != 200) {
                if ($httpcode >= 500 && $httpcode <= 599) {
                    $statuscode = self::$IS_SERVERERROR;
                    $err = 'got http-code ' . $httpcode . ' (server error)';
                } else {
                    $statuscode = self::$IS_HTTPERROR;
                    $err = 'got http-code ' . $httpcode;
                }
            }
        }

        if ($statuscode == 0) {
            $jbody = json_decode($body, true);
            if ($jbody == false) {
                $statuscode = self::$IS_INVALIDDATA;
                $err = 'invalid/malformed data';
            }
        }

        if ($statuscode == 0) {
            if (isset($jbody['result'])) {
                $e = [
                    1  => 'Success',
                    2  => 'Unauthorized',
                    3  => 'Mismatch',
                    16 => 'Data missing',
                    17 => 'Out of Range',
                    18 => 'Data format error',
                    19 => 'RF code error',
                    32 => 'Page not found',
                    48 => 'Not permitted',
                ];
                $r = $jbody['result'];
                $s = isset($e[$r]) ? $e[$r] : 'unknown result ' . $r;
                switch ($r) {
                    case 1: // Success
                        $this->SendDebug(__FUNCTION__, '    result=' . $s, 0);
                        break;
                    case 2: // Unauthorized
                        $statuscode = self::$IS_UNAUTHORIZED;
                        $err = $s;
                        break;
                    case 32: // Page not found
                    case 48: // Not permitted
                        $statuscode = self::$IS_FORBIDDEN;
                        $err = $s;
                        break;
                    default:
                        $statuscode = self::$IS_INVALIDDATA;
                        $err = $s;
                        break;
                }
            }
        }

        if ($statuscode) {
            $this->SendDebug(__FUNCTION__, '    statuscode=' . $statuscode . ', err=' . $err, 0);
            $this->MaintainStatus($statuscode);
            return '';
        }

        $this->MaintainStatus(IS_ACTIVE);
        return $body;
    }

    public function SetZoneDisabled(bool $value)
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $sid = $this->GetValue('ZoneSelection');
        // check 0

        $byte = floor(($sid - 1) / 8);
        $bit = ($sid - 1) % 8;

        $bval = 0;
        for ($i = 0; $i < 8; $i++) {
            $post = '_' . (($byte * 8) + $i + 1);
            $b = $this->GetValue('ZoneDisabled' . $post);
            if ($b) {
                $bval = $this->bit_set($bval, $i);
            }
        }
        if ($value) {
            $bval = $this->bit_set($bval, $bit);
        } else {
            $bval = $this->bit_clear($bval, $bit);
        }

        $params = [
            'd' . $byte => $bval,
        ];
        $data = $this->do_HttpRequest('cs', $params);
        return $data != false;
    }

    public function SetZoneIgnoreRain(bool $value)
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $sid = $this->GetValue('ZoneSelection');
        // check 0

        $byte = floor(($sid - 1) / 8);
        $bit = ($sid - 1) % 8;

        $bval = 0;
        for ($i = 0; $i < 8; $i++) {
            $post = '_' . (($byte * 8) + $i + 1);
            $b = $this->GetValue('ZoneIgnoreRain' . $post);
            if ($b) {
                $bval = $this->bit_set($bval, $i);
            }
        }
        if ($value) {
            $bval = $this->bit_set($bval, $bit);
        } else {
            $bval = $this->bit_clear($bval, $bit);
        }

        $params = [
            'i' . $byte => $bval,
        ];
        $data = $this->do_HttpRequest('cs', $params);
        return $data != false;
    }

    public function SetZoneIgnoreSensor1(bool $value)
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $sid = $this->GetValue('ZoneSelection');
        // check 0

        $byte = floor(($sid - 1) / 8);
        $bit = ($sid - 1) % 8;

        $bval = 0;
        for ($i = 0; $i < 8; $i++) {
            $post = '_' . (($byte * 8) + $i + 1);
            $b = $this->GetValue('ZoneIgnoreSensor1' . $post);
            if ($b) {
                $bval = $this->bit_set($bval, $i);
            }
        }
        if ($value) {
            $bval = $this->bit_set($bval, $bit);
        } else {
            $bval = $this->bit_clear($bval, $bit);
        }

        $params = [
            'j' . $byte => $bval,
        ];
        $data = $this->do_HttpRequest('cs', $params);
        return $data != false;
    }

    public function SetZoneIgnoreSensor2(bool $value)
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $sid = $this->GetValue('ZoneSelection');
        // check 0

        $byte = floor(($sid - 1) / 8);
        $bit = ($sid - 1) % 8;

        $bval = 0;
        for ($i = 0; $i < 8; $i++) {
            $post = '_' . (($byte * 8) + $i + 1);
            $b = $this->GetValue('ZoneIgnoreSensor2' . $post);
            if ($b) {
                $bval = $this->bit_set($bval, $i);
            }
        }
        if ($value) {
            $bval = $this->bit_set($bval, $bit);
        } else {
            $bval = $this->bit_clear($bval, $bit);
        }

        $params = [
            'k' . $byte => $bval,
        ];
        $data = $this->do_HttpRequest('cs', $params);
        return $data != false;
    }

    public function SetProgramEnabled(bool $value)
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $pid = $this->GetValue('ProgramSelection');
        // check 0

        $params = [
            'pid' => ($pid - 1),
            'en'  => ($value ? 1 : 0),
        ];
        $data = $this->do_HttpRequest('cp', $params);
        return $data != false;
    }

    public function SetProgramWeatherAdjust(bool $value)
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $pid = $this->GetValue('ProgramSelection');
        // check 0

        $params = [
            'pid' => ($pid - 1),
            'uwt' => ($value ? 1 : 0),
        ];
        $data = $this->do_HttpRequest('cp', $params);
        return $data != false;
    }

    private function AdjustVariablenames()
    {
        $n_changed = 0;

        $chldIDs = IPS_GetChildrenIDs($this->InstanceID);

        $zone_list = @json_decode($this->ReadPropertyString('zone_list'), true);
        if ($zone_list === false) {
            $zone_list = [];
        }
        for ($zone_n = 0; $zone_n < count($zone_list); $zone_n++) {
            $zone_entry = $zone_list[$zone_n];

            foreach ($chldIDs as $chldID) {
                $obj = IPS_GetObject($chldID);
                if ($obj['ObjectType'] == OBJECTTYPE_VARIABLE) {
                    if (preg_match('#^Zone[^_]+_' . ($zone_n + 1) . '$#', $obj['ObjectIdent'], $r)) {
                        if (preg_match('/Z[0-9]{2}\[[^\]]*\]:[ ]*(.*)$/', $obj['ObjectName'], $r)) {
                            $s = sprintf('Z%02d[%s]: %s', $zone_n + 1, $zone_entry['name'], $r[1]);
                            if ($obj['ObjectName'] != $s) {
                                IPS_SetName($chldID, $s);
                                $this->SendDebug(__FUNCTION__, 'id=' . $chldID . ', ident=' . $obj['ObjectIdent'] . ': rename from "' . $obj['ObjectName'] . '" to "' . $s . '"', 0);
                                $n_changed++;
                            }
                        }
                    }
                }
            }
        }
        $program_list = @json_decode($this->ReadPropertyString('program_list'), true);
        if ($program_list === false) {
            $program_list = [];
        }
        for ($program_n = 0; $program_n < count($program_list); $program_n++) {
            $program_entry = $program_list[$program_n];

            foreach ($chldIDs as $chldID) {
                $obj = IPS_GetObject($chldID);
                if ($obj['ObjectType'] == OBJECTTYPE_VARIABLE) {
                    if (preg_match('#^Program[^_]+_' . ($program_n + 1) . '$#', $obj['ObjectIdent'], $r)) {
                        if (preg_match('/P[0-9]{2}\[[^\]]*\]:[ ]*(.*)$/', $obj['ObjectName'], $r)) {
                            $s = sprintf('P%02d[%s]: %s', $program_n + 1, $program_entry['name'], $r[1]);
                            if ($obj['ObjectName'] != $s) {
                                IPS_SetName($chldID, $s);
                                $this->SendDebug(__FUNCTION__, 'id=' . $chldID . ', ident=' . $obj['ObjectIdent'] . ': rename from "' . $obj['ObjectName'] . '" to "' . $s . '"', 0);
                                $n_changed++;
                            }
                        }
                    }
                }
            }
        }
        $this->SendDebug(__FUNCTION__, 'name of ' . $n_changed . ' variables changed', 0);
    }

    private function SetZoneSelection(int $idx)
    {
        $zone_infos = @json_decode($this->ReadAttributeString('zone_infos'), true);
        if ($zone_infos == false) {
            $this->SendDebug(__FUNCTION__, 'no zone_infos', 0);
            return false;
        }

        if ($idx == 0) {
            $this->SetValue('ZoneState', self::$CONTROLLER_STATE_DISABLED);
            $this->SetValue('ZoneDisabled', false);
            $this->SetValue('ZoneIgnoreRain', false);
            $this->SetValue('ZoneIgnoreSensor1', false);
            $this->SetValue('ZoneIgnoreSensor2', false);
            $this->SetValue('ZoneInfo', '');

            return true;
        }

        $zone_info = false;
        for ($i = 0; $i < count($zone_infos); $i++) {
            if ($zone_infos[$i]['sid'] == $idx) {
                $zone_info = $zone_infos[$i];
                break;
            }
        }
        if ($zone_info === false) {
            $this->SendDebug(__FUNCTION__, 'no zone_info for idx=' . $idx, 0);
            return false;
        }

        $this->SendDebug(__FUNCTION__, 'zone_infos=' . print_r($zone_infos, true), 0);

        $post = '_' . ($i + 1);

        $this->SetValue('ZoneState', $this->GetValue('ZoneState' . $post));
        $this->SetValue('ZoneDisabled', $zone_info['disabled']);
        $this->SetValue('ZoneIgnoreRain', $zone_info['ignore_rain']);
        $this->SetValue('ZoneIgnoreSensor1', $zone_info['ignore_sn1']);
        $this->SetValue('ZoneIgnoreSensor2', $zone_info['ignore_sn2']);
        $this->SetValue('ZoneInfo', $zone_info['info']);

        return true;
    }

    private function SetProgramSelection(int $idx)
    {
        $program_infos = @json_decode($this->ReadAttributeString('program_infos'), true);
        if ($program_infos == false) {
            $this->SendDebug(__FUNCTION__, 'no program_infos', 0);
            return false;
        }

        if ($idx == 0) {
            $this->SetValue('ProgramEnabled', false);
            $this->SetValue('ProgramWeatherAdjust', false);
            $this->SetValue('ProgramInfo', '');

            return true;
        }

        $program_info = false;
        for ($i = 0; $i < count($program_infos); $i++) {
            if ($program_infos[$i]['pid'] == $idx) {
                $program_info = $program_infos[$i];
                break;
            }
        }
        if ($program_info === false) {
            $this->SendDebug(__FUNCTION__, 'no program_info for idx=' . $idx, 0);
            return false;
        }

        $this->SendDebug(__FUNCTION__, 'program_infos=' . print_r($program_infos, true), 0);

        $this->SetValue('ProgramEnabled', $program_info['enabled']);
        $this->SetValue('ProgramWeatherAdjust', $program_info['weather_adjustment']);
        $this->SetValue('ProgramInfo', $program_info['info']);

        return true;
    }

    private function UpdateVarProfileAssociations(string $ident, $associations = null)
    {
        $varProfile = IPS_GetVariableProfile($ident);
        $old_associations = $varProfile['Associations'];
        foreach ($old_associations as $old_a) {
            $fnd = false;
            foreach ($associations as $a) {
                if ($old_a['Value'] == $a['Value']) {
                    $fnd = true;
                    break;
                }
            }
            if ($fnd == false) {
                $associations[] = [
                    'Value' => $old_a['Value'],
                    'Name'  => '',
                ];
            }
        }
        foreach ($associations as $a) {
            IPS_SetVariableProfileAssociation($ident, $a['Value'], $a['Name'], '', -1);
        }
    }
}
