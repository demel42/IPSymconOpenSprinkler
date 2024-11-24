<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';
require_once __DIR__ . '/../libs/local.php';

class OpenSprinkler extends IPSModule
{
    use OpenSprinkler\StubsCommonLib;
    use OpenSprinklerLocalLib;

    public static $MAX_INT_SENSORS = 2;
    public static $MAX_ZONES = 200;

    public function __construct(string $InstanceID)
    {
        parent::__construct($InstanceID);

        $this->CommonConstruct(__DIR__);
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

        $this->RegisterAttributeString('UpdateInfo', json_encode([]));
        $this->RegisterAttributeString('ModuleStats', json_encode([]));

        $this->InstallVarProfiles(false);

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

    private function GetAllChildenIDs($objID, &$objIDs)
    {
        $cIDs = IPS_GetChildrenIDs($objID);
        if ($cIDs != []) {
            $objIDs = array_merge($objIDs, $cIDs);
            foreach ($cIDs as $cID) {
                $this->GetAllChildenIDs($cID, $objIDs);
            }
        }
    }

    private function GetAllIdents()
    {
        $objIDs = [];
        $this->GetAllChildenIDs($this->InstanceID, $objIDs);
        $map = [];
        foreach ($objIDs as $objID) {
            $obj = IPS_GetObject($objID);
            $map[$obj['ObjectIdent']] = $objID;
        }
        return $map;
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

        $this->MaintainVariable('CurrentDraw', $this->Translate('Actual current draw'), VARIABLETYPE_INTEGER, 'OpenSprinkler.Current', $vpos++, true);

        $this->MaintainVariable('WeatherQueryTstamp', $this->Translate('Timestamp of last weather information'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, true);
        $this->MaintainVariable('WeatherQueryStatus', $this->Translate('Status of last weather query'), VARIABLETYPE_INTEGER, 'OpenSprinkler.WeatherQueryStatus', $vpos++, true);

        $this->MaintainVariable('DeviceTime', $this->Translate('Device time'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, true);
        $this->MaintainVariable('WifiStrength', $this->Translate('Wifi signal strenght'), VARIABLETYPE_INTEGER, 'OpenSprinkler.Wifi', $vpos++, true);

        $this->MaintainVariable('LastRebootTstamp', $this->Translate('Timestamp of last reboot'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, true);
        $this->MaintainVariable('LastRebootCause', $this->Translate('Cause of last reboot'), VARIABLETYPE_INTEGER, 'OpenSprinkler.RebootCause', $vpos++, true);

        $this->MaintainVariable('LastUpdate', $this->Translate('Last update'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, true);

        $sensor_list = @json_decode($this->ReadPropertyString('sensor_list'), true);
        if ($sensor_list === false) {
            $sensor_list = [];
        }
        $f_use = false;
        // 200+n_sensors*100+1: 2 Sensoren
        for ($i = 0; $i < self::$MAX_INT_SENSORS; $i++) {
            $vpos = 200 + $i * 100 + 1;
            $post = '_' . ($i + 1);
            $s = ' (SN' . ($i + 1) . ')';

            $snt = $this->GetArrayElem($sensor_list, $i . '.type', self::$SENSOR_TYPE_NONE);
            $use = (bool) $this->GetArrayElem($sensor_list, $i . '.use', false);

            $c_use = $use && in_array($snt, [self::$SENSOR_TYPE_RAIN, self::$SENSOR_TYPE_SOIL]);
            $this->MaintainVariable('SensorState' . $post, $this->SensorType2String($snt) . $s, VARIABLETYPE_BOOLEAN, 'OpenSprinkler.SensorState', $vpos++, $c_use);

            if ($use && $snt == self::$SENSOR_TYPE_FLOW) {
                $f_use = true;
                $this->MaintainVariable('WaterFlowrate', $this->Translate('Water flow rate (actual)') . $s, VARIABLETYPE_FLOAT, 'OpenSprinkler.WaterFlowrate', $vpos++, $f_use);
                /*
                    $this->MaintainVariable('DailyWaterUsage', $this->Translate('Water usage (today)'), VARIABLETYPE_FLOAT, 'OpenSprinkler.Flowmeter', $vpos++, $with_daily_value);
                 */
            }
        }
        if ($f_use == false) {
            $this->UnregisterVariable('WaterFlowrate');
        }

        $zone_list = @json_decode($this->ReadPropertyString('zone_list'), true);
        if ($zone_list === false) {
            $zone_list = [];
        }
        $n_zones = count($zone_list);
        // 1000+n_zones*100+1: x Zonen
        for ($i = 0; $i < self::$MAX_ZONES; $i++) {
            $use = (bool) $this->GetArrayElem($zone_list, $i . '.use', false);
        }

        /*
                // letzter Bewässerungszyklus
                $this->MaintainVariable('LastRun', $this->Translate('Last run'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, true);
                $this->MaintainVariable('LastDuration', $this->Translate('Duration of last run'), VARIABLETYPE_INTEGER, 'OpenSprinkler.Duration', $vpos++, true);

                // nächster Bewässerungszyklus
                $this->MaintainVariable('NextRun', $this->Translate('Next run'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, true);
                $this->MaintainVariable('NextDuration', $this->Translate('Duration of next run'), VARIABLETYPE_INTEGER, 'OpenSprinkler.Duration', $vpos++, true);

                // aktueller Bewässerungszyklus
                $this->MaintainVariable('TimeLeft', $this->Translate('Time left'), VARIABLETYPE_STRING, '', $vpos++, true);
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

        $sensor_list = @json_decode($this->ReadPropertyString('sensor_list'), true);
        if ($sensor_list === false) {
            $sensor_list = [];
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

        $wl = $this->GetArrayElem($jdata, 'options.wl', 0, $fnd);
        if ($fnd) {
            $this->SendDebug(__FUNCTION__, '... WateringLevel (options.wl)=' . $wl, 0);
            $this->SetValue('WateringLevel', $wl);
        }

        $rdst = $this->GetArrayElem($jdata, 'settings.rdst', 0, $fnd);
        if ($fnd) {
            $this->SendDebug(__FUNCTION__, '... RainDelayUntil (settings.rdst)=' . $rdst, 0);
            $this->SetValue('RainDelayUntil', $rdst);
        }

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
            $post = '_' . ($i + 1);

            $snt = $this->GetArrayElem($sensor_list, $i . '.type', self::$SENSOR_TYPE_NONE);
            $use = (bool) $this->GetArrayElem($sensor_list, $i . '.use', false);

            if ($use && in_array($snt, [self::$SENSOR_TYPE_RAIN, self::$SENSOR_TYPE_SOIL])) {
                $sn = $this->GetArrayElem($jdata, 'settings.sn' . ($i + 1), 0, $fnd);
                if ($fnd) {
                    $ident = 'SensorState' . $post;
                    $this->SendDebug(__FUNCTION__, '... ' . $ident . ' (settings.sn' . ($i + 1) . ')=' . $sn, 0);
                    $this->SetValue($ident, $sn);
                }
            }

            if ($use && $snt == self::$SENSOR_TYPE_FLOW) {
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
            $this->SendDebug(__FUNCTION__, '... ?? (settings.lrun)=' . print_r($lrun, true), 0);
            $sid = $lrun[0];
            $pid = $lrun[1];
            $dur = $lrun[2];
            $end = $this->AdjustTimestamp($lrun[3]);
            $this->SendDebug(__FUNCTION__, '....... sid=' . $sid . ', pid=' . $pid . ', dur=' . $dur . ', end=' . ($end ? date('d.m.y H:i:s', $end) : '-'), 0);
        }

        /*
            Station status bits. Each byte in this array corresponds to an 8-station board and represents the bit field (LSB).
            For example, 1 means the 1st station on the board is open, 192 means the 7th and 8th stations are open.
         */
        $sbits = (array) $this->GetArrayElem($jdata, 'settings.sbits', [], $fnd);
        if ($fnd) {
            $this->SendDebug(__FUNCTION__, '... ?? (settings.sbits)=' . print_r($sbits, true), 0);
            for ($sid = 0; $sid < count($sbits) * 8; $sid++) {
                $active = $this->idx_in_bytes($sid, $sbits);
                $this->SendDebug(__FUNCTION__, '....... sid=' . $sid . ', active=' . $this->bool2str($active), 0);
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
            $this->SendDebug(__FUNCTION__, '... ?? (settings.ps)=' . print_r($ps, true), 0);
            for ($sid = 0; $sid < count($ps); $sid++) {
                $pid = $ps[$sid][0];
                $rem = $ps[$sid][1];
                $start = $this->AdjustTimestamp($ps[$sid][2]);
                $gid = $ps[$sid][3];
                $this->SendDebug(__FUNCTION__, '....... sid=' . $sid . ', pid=' . $pid . ', rem=' . $rem . 's, start=' . ($start ? date('d.m.y H:i:s', $start) : '-') . ', gid=' . $this->Group2String($gid), 0);
            }
        }

        $this->SetValue('LastUpdate', $now);

        $this->SendDebug(__FUNCTION__, $this->PrintTimer('QueryStatus'), 0);
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
        $ignore_rain = $this->GetArrayElem($ja_data, 'stations.ignore_rain', 0);
        $ignore_sn1 = $this->GetArrayElem($ja_data, 'stations.ignore_sn1', 0);
        $ignore_sn2 = $this->GetArrayElem($ja_data, 'stations.ignore_sn2', 0);
        $stn_dis = (array) $this->GetArrayElem($ja_data, 'stations.stn_dis', []);
        $stn_grp = (array) $this->GetArrayElem($ja_data, 'stations.stn_grp', []);
        $stn_spe = (array) $this->GetArrayElem($ja_data, 'stations.stn_spe', []);
        for ($idx = 0; $idx < $maxlen; $idx++) {
            if ($this->idx_in_bytes($idx, $stn_dis)) {
                continue;
            }
            $sname = $this->GetArrayElem($ja_data, 'stations.snames.' . $idx, '');
            $stn_grp = $this->GetArrayElem($ja_data, 'stations.stn_grp.' . $idx, 0);
            $infos = [];
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
        for ($idx = 1; $idx <= 2; $idx++) {
            $snt = $this->GetArrayElem($ja_data, 'options.sn' . $idx . 't', 0);
            switch ($snt) {
                case self::$SENSOR_TYPE_RAIN:
                    $sno = $this->GetArrayElem($ja_data, 'options.sn' . $idx . 'o', 0);
                    $sensor_list[] = [
                        'no'    => $idx,
                        'type'  => $snt,
                        'name'  => $this->SensorType2String($snt),
                        'info'  => $this->Translate('Contact variant') . ': ' . $this->SensorType2String($sno),
                        'use'   => true,
                    ];
                    break;
                case self::$SENSOR_TYPE_FLOW:
                    $fpr0 = $this->GetArrayElem($ja_data, 'options.fpr0', 0);
                    $fpr1 = $this->GetArrayElem($ja_data, 'options.fpr1', 0);
                    $fpr = (($fpr1 << 8) + $fpr0) / 100.0;
                    $sensor_list[] = [
                        'no'              => $idx,
                        'type'            => $snt,
                        'name'            => $this->SensorType2String($snt),
                        'info'            => $this->TranslateFormat('Resolution: {$fpr} l/pulse', ['{$fpr}' => $fpr]),
                        'use'             => true,
                    ];
                    break;
                case self::$SENSOR_TYPE_SOIL:
                    $sno = $this->GetArrayElem($ja_data, 'options.sn' . $idx . 'o', 0);
                    $sensor_list[] = [
                        'no'    => $idx,
                        'type'  => $snt,
                        'name'  => $this->SensorType2String($snt),
                        'use'   => true,
                        'info'  => $this->Translate($sno ? 'normally open' : 'normally closed'),
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

        $r = false;
        switch ($ident) {
            default:
                $this->SendDebug(__FUNCTION__, 'invalid ident ' . $ident, 0);
                break;
        }
        if ($r) {
            $this->SetValue($ident, $value);
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

        /*
        #define HTML_OK               0x00
        #define HTML_SUCCESS          0x01
        #define HTML_UNAUTHORIZED     0x02
        #define HTML_MISMATCH         0x03
        #define HTML_DATA_MISSING     0x10
        #define HTML_DATA_OUTOFBOUND  0x11
        #define HTML_DATA_FORMATERROR 0x12
        #define HTML_RFCODE_ERROR     0x13
        #define HTML_PAGE_NOT_FOUND   0x20
        #define HTML_NOT_PERMITTED    0x30
        #define HTML_UPLOAD_FAILED    0x40
        #define HTML_REDIRECT_HOME    0xFF

        "result":1} {"result":2} {"result":3} {"result":16} {"result":17} {"result":18} {"result":19} {"result":32} {"result":48}
        Major Changes



        (e.g. missing password or password is incorrect)
        (e.g. new password and confirmation password do not match)
        (e.g. missing required parameters)
        (e.g. value exceeds the acceptable range)
        (e.g. provided data does not match required format)
        (e.g. RF code does not match required format)
        (e.g. page not found or requested file missing)
        (e.g. cannot operate on the requested station)

         */

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
}

/*

stations.stn_fas=Strömungsüberwachungs-Grenzmenge in l/min (/100)
stations.stn_favg=Durchschnittliche Strömungsmenge in l/min (/100)

17.11.2024, 14:11:53 | RetriveConfiguration | jdata=Array
(
    [settings] => Array
        (
            [devt] => 1731852712
            [nbrd] => 4
            [en] => 1
            [sn1] => 0
            [sn2] => 0
            [rd] => 0
            [rdst] => 0
            [sunrise] => 474
            [sunset] => 1001
            [eip] => 2728303349
            [lwc] => 1731841200
            [lswc] => 1731841200
            [lupt] => 1731765236
            [lrbtc] => 99
            [lrun] => Array
                (
                    [0] => 3
                    [1] => 2
                    [2] => 900
                    [3] => 1731842101
                )

            [pq] => 0
            [pt] => 0
            [nq] => 0
            [RSSI] => -43
            [otc] => Array
                (
                    [en] => 1
                    [token] => OT8a801789b8e5986d6c2771d8b192e7
                    [server] => ws.cloud.openthings.io
                    [port] => 80
                )

            [otcs] => 3
            [mac] => 00:F9:E0:4B:03:9F
            [loc] => 51.46101,7.15844
            [jsp] => https://ui.opensprinklershop.de/js
            [wsp] => weather.opensprinkler.com
            [wto] => Array
                (
                    [scales] => Array
                        (
                            [0] => 100
                            [1] => 100
                            [2] => 100
                            [3] => 100
                            [4] => 100
                            [5] => 100
                            [6] => 100
                            [7] => 100
                            [8] => 100
                            [9] => 100
                            [10] => 100
                            [11] => 100
                        )

                    [pws] => IBOCHUM22
                    [key] => a7e2b3fcef09481da2b3fcef09281d41
                )

            [ifkey] =>
            [mqtt] => Array
                (
                    [en] => 1
                    [host] => 192.168.178.68
                    [port] => 1883
                    [user] => mqtt4ips
                    [pass] => ws6#HL4(hn
                    [pubt] => opensprinkler-controller
                    [subt] => OS-00F9E04B039F
                )

            [wtdata] => Array
                (
                    [wp] => Manual
                )

            [wterr] => 0
            [dname] => OpenSprinkler-Controller
            [email] => Array
                (
                )

            [curr] => 0
            [flcrt] => 0
            [flwrt] => 30
            [sbits] => Array
                (
                    [0] => 0
                    [1] => 0
                    [2] => 0
                    [3] => 0
                    [4] => 0
                )

            [ps] => Array
                (
                    [0] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                    [1] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                    [2] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                    [3] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                    [4] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                    [5] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                    [6] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                    [7] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                    [8] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                    [9] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                    [10] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                    [11] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                    [12] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                    [13] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                    [14] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                    [15] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                    [16] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                    [17] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                    [18] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                    [19] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                    [20] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                    [21] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                    [22] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                    [23] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                    [24] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                    [25] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                    [26] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                    [27] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                    [28] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                    [29] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                    [30] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                    [31] => Array
                        (
                            [0] => 0
                            [1] => 0
                            [2] => 0
                            [3] => 0
                        )

                )

            [gpio] => Array
                (
                )

            [influxdb] => Array
                (
                    [en] => 0
                )

        )

    [programs] => Array
        (
            [nprogs] => 6
            [nboards] => 4
            [mnp] => 40
            [mnst] => 4
            [pnsize] => 32
            [pd] => Array
                (
                    [0] => Array
                        (
                            [0] => 115
                            [1] => 0
                            [2] => 1
                            [3] => Array
                                (
                                    [0] => 420
                                    [1] => -1
                                    [2] => -1
                                    [3] => -1
                                )

                            [4] => Array
                                (
                                    [0] => 0
                                    [1] => 0
                                    [2] => 0
                                    [3] => 0
                                    [4] => 0
                                    [5] => 600
                                    [6] => 600
                                    [7] => 600
                                    [8] => 600
                                    [9] => 0
                                    [10] => 0
                                    [11] => 0
                                    [12] => 0
                                    [13] => 0
                                    [14] => 0
                                    [15] => 0
                                    [16] => 0
                                    [17] => 0
                                    [18] => 0
                                    [19] => 0
                                    [20] => 0
                                    [21] => 0
                                    [22] => 0
                                    [23] => 0
                                    [24] => 0
                                    [25] => 0
                                    [26] => 0
                                    [27] => 0
                                    [28] => 0
                                    [29] => 0
                                    [30] => 0
                                    [31] => 0
                                )

                            [5] => Beete
                            [6] => Array
                                (
                                    [0] => 0
                                    [1] => 33
                                    [2] => 415
                                )

                        )

                    [1] => Array
                        (
                            [0] => 3
                            [1] => 127
                            [2] => 0
                            [3] => Array
                                (
                                    [0] => 660
                                    [1] => 0
                                    [2] => 0
                                    [3] => 0
                                )

                            [4] => Array
                                (
                                    [0] => 0
                                    [1] => 0
                                    [2] => 0
                                    [3] => 900
                                    [4] => 0
                                    [5] => 0
                                    [6] => 0
                                    [7] => 0
                                    [8] => 0
                                    [9] => 0
                                    [10] => 0
                                    [11] => 0
                                    [12] => 0
                                    [13] => 0
                                    [14] => 0
                                    [15] => 0
                                    [16] => 0
                                    [17] => 0
                                    [18] => 0
                                    [19] => 0
                                    [20] => 0
                                    [21] => 0
                                    [22] => 0
                                    [23] => 0
                                    [24] => 0
                                    [25] => 0
                                    [26] => 0
                                    [27] => 0
                                    [28] => 0
                                    [29] => 0
                                    [30] => 0
                                    [31] => 0
                                )

                            [5] => Pflanztrog
                            [6] => Array
                                (
                                    [0] => 0
                                    [1] => 33
                                    [2] => 415
                                )

                        )

                    [2] => Array
                        (
                            [0] => 51
                            [1] => 0
                            [2] => 1
                            [3] => Array
                                (
                                    [0] => 570
                                    [1] => 0
                                    [2] => 0
                                    [3] => 0
                                )

                            [4] => Array
                                (
                                    [0] => 300
                                    [1] => 0
                                    [2] => 300
                                    [3] => 0
                                    [4] => 0
                                    [5] => 0
                                    [6] => 0
                                    [7] => 0
                                    [8] => 0
                                    [9] => 0
                                    [10] => 0
                                    [11] => 0
                                    [12] => 0
                                    [13] => 0
                                    [14] => 0
                                    [15] => 0
                                    [16] => 0
                                    [17] => 0
                                    [18] => 0
                                    [19] => 0
                                    [20] => 0
                                    [21] => 0
                                    [22] => 0
                                    [23] => 0
                                    [24] => 300
                                    [25] => 300
                                    [26] => 0
                                    [27] => 0
                                    [28] => 0
                                    [29] => 0
                                    [30] => 0
                                    [31] => 0
                                )

                            [5] => Gefäße
                            [6] => Array
                                (
                                    [0] => 0
                                    [1] => 33
                                    [2] => 415
                                )

                        )

                    [3] => Array
                        (
                            [0] => 51
                            [1] => 0
                            [2] => 1
                            [3] => Array
                                (
                                    [0] => 300
                                    [1] => 0
                                    [2] => 0
                                    [3] => 0
                                )

                            [4] => Array
                                (
                                    [0] => 0
                                    [1] => 300
                                    [2] => 0
                                    [3] => 0
                                    [4] => 0
                                    [5] => 0
                                    [6] => 0
                                    [7] => 0
                                    [8] => 0
                                    [9] => 0
                                    [10] => 300
                                    [11] => 900
                                    [12] => 600
                                    [13] => 600
                                    [14] => 420
                                    [15] => 0
                                    [16] => 0
                                    [17] => 0
                                    [18] => 0
                                    [19] => 0
                                    [20] => 0
                                    [21] => 0
                                    [22] => 0
                                    [23] => 0
                                    [24] => 300
                                    [25] => 300
                                    [26] => 0
                                    [27] => 900
                                    [28] => 0
                                    [29] => 0
                                    [30] => 0
                                    [31] => 0
                                )

                            [5] => Gefäße, Hochbeete morgens
                            [6] => Array
                                (
                                    [0] => 0
                                    [1] => 33
                                    [2] => 415
                                )

                        )

                    [4] => Array
                        (
                            [0] => 51
                            [1] => 0
                            [2] => 1
                            [3] => Array
                                (
                                    [0] => 900
                                    [1] => 0
                                    [2] => 0
                                    [3] => 0
                                )

                            [4] => Array
                                (
                                    [0] => 0
                                    [1] => 300
                                    [2] => 0
                                    [3] => 0
                                    [4] => 0
                                    [5] => 0
                                    [6] => 0
                                    [7] => 0
                                    [8] => 0
                                    [9] => 0
                                    [10] => 300
                                    [11] => 900
                                    [12] => 600
                                    [13] => 600
                                    [14] => 0
                                    [15] => 0
                                    [16] => 0
                                    [17] => 0
                                    [18] => 0
                                    [19] => 0
                                    [20] => 0
                                    [21] => 0
                                    [22] => 0
                                    [23] => 0
                                    [24] => 300
                                    [25] => 300
                                    [26] => 0
                                    [27] => 900
                                    [28] => 0
                                    [29] => 0
                                    [30] => 0
                                    [31] => 0
                                )

                            [5] => Gefäße, Hochbeete nachmittags
                            [6] => Array
                                (
                                    [0] => 0
                                    [1] => 33
                                    [2] => 415
                                )

                        )

                    [5] => Array
                        (
                            [0] => 2
                            [1] => 1
                            [2] => 0
                            [3] => Array
                                (
                                    [0] => 330
                                    [1] => 0
                                    [2] => 0
                                    [3] => 0
                                )

                            [4] => Array
                                (
                                    [0] => 0
                                    [1] => 0
                                    [2] => 0
                                    [3] => 0
                                    [4] => 0
                                    [5] => 0
                                    [6] => 0
                                    [7] => 0
                                    [8] => 0
                                    [9] => 0
                                    [10] => 0
                                    [11] => 0
                                    [12] => 0
                                    [13] => 0
                                    [14] => 0
                                    [15] => 600
                                    [16] => 600
                                    [17] => 600
                                    [18] => 300
                                    [19] => 300
                                    [20] => 300
                                    [21] => 0
                                    [22] => 0
                                    [23] => 0
                                    [24] => 0
                                    [25] => 0
                                    [26] => 0
                                    [27] => 0
                                    [28] => 0
                                    [29] => 0
                                    [30] => 0
                                    [31] => 0
                                )

                            [5] => Rasen
                            [6] => Array
                                (
                                    [0] => 0
                                    [1] => 33
                                    [2] => 415
                                )

                        )

                )

        )

    [options] => Array
        (
            [fwv] => 233
            [tz] => 52
            [ntp] => 1
            [dhcp] => 0
            [ip1] => 192
            [ip2] => 168
            [ip3] => 178
            [ip4] => 94
            [gw1] => 192
            [gw2] => 168
            [gw3] => 178
            [gw4] => 1
            [hp0] => 80
            [hp1] => 0
            [hwv] => 33
            [ext] => 3
            [sdt] => 0
            [mas] => 0
            [mton] => 0
            [mtof] => 0
            [wl] => 100
            [den] => 1
            [ipas] => 0
            [devid] => 0
            [dim] => 15
            [uwt] => 4
            [ntp1] => 192
            [ntp2] => 168
            [ntp3] => 178
            [ntp4] => 1
            [lg] => 1
            [mas2] => 0
            [mton2] => 0
            [mtof2] => 0
            [fwm] => 171
            [fpr0] => 232
            [fpr1] => 3
            [re] => 0
            [dns1] => 192
            [dns2] => 168
            [dns3] => 178
            [dns4] => 2
            [sar] => 0
            [ife] => 255
            [sn1t] => 2
            [sn1o] => 1
            [sn2t] => 1
            [sn2o] => 1
            [sn1on] => 0
            [sn1of] => 0
            [sn2on] => 0
            [sn2of] => 0
            [subn1] => 255
            [subn2] => 255
            [subn3] => 255
            [subn4] => 0
            [fwire] => 1
            [ife2] => 31
            [resv4] => 0
            [resv5] => 0
            [resv6] => 0
            [resv7] => 0
            [resv8] => 0
            [wimod] => 42
            [reset] => 0
            [feature] => ASB
            [dexp] => 2
            [mexp] => 8
            [hwt] => 172
            [ms] => Array
                (
                    [0] => 0
                    [1] => 120
                    [2] => 120
                    [3] => 0
                    [4] => 120
                    [5] => 120
                )

        )

    [status] => Array
        (
            [sn] => Array
                (
                    [0] => 0
                    [1] => 0
                    [2] => 0
                    [3] => 0
                    [4] => 0
                    [5] => 0
                    [6] => 0
                    [7] => 0
                    [8] => 0
                    [9] => 0
                    [10] => 0
                    [11] => 0
                    [12] => 0
                    [13] => 0
                    [14] => 0
                    [15] => 0
                    [16] => 0
                    [17] => 0
                    [18] => 0
                    [19] => 0
                    [20] => 0
                    [21] => 0
                    [22] => 0
                    [23] => 0
                    [24] => 0
                    [25] => 0
                    [26] => 0
                    [27] => 0
                    [28] => 0
                    [29] => 0
                    [30] => 0
                    [31] => 0
                )

            [nstations] => 32
        )

    [stations] => Array
        (
            [masop] => Array
                (
                    [0] => 255
                    [1] => 255
                    [2] => 255
                    [3] => 255
                )

            [masop2] => Array
                (
                    [0] => 0
                    [1] => 0
                    [2] => 0
                    [3] => 0
                )

            [ignore_rain] => Array
                (
                    [0] => 17
                    [1] => 4
                    [2] => 0
                    [3] => 11
                )

            [ignore_sn1] => Array
                (
                    [0] => 0
                    [1] => 0
                    [2] => 0
                    [3] => 10
                )

            [ignore_sn2] => Array
                (
                    [0] => 0
                    [1] => 0
                    [2] => 0
                    [3] => 10
                )

            [stn_dis] => Array
                (
                    [0] => 0
                    [1] => 0
                    [2] => 224
                    [3] => 224
                )

            [stn_spe] => Array
                (
                    [0] => 0
                    [1] => 0
                    [2] => 0
                    [3] => 255
                )

            [stn_grp] => Array
                (
                    [0] => 0
                    [1] => 0
                    [2] => 0
                    [3] => 0
                    [4] => 0
                    [5] => 0
                    [6] => 0
                    [7] => 0
                    [8] => 0
                    [9] => 0
                    [10] => 0
                    [11] => 0
                    [12] => 0
                    [13] => 0
                    [14] => 0
                    [15] => 0
                    [16] => 0
                    [17] => 0
                    [18] => 0
                    [19] => 0
                    [20] => 0
                    [21] => 0
                    [22] => 0
                    [23] => 0
                    [24] => 0
                    [25] => 0
                    [26] => 0
                    [27] => 0
                    [28] => 0
                    [29] => 0
                    [30] => 0
                    [31] => 0
                )

            [stn_fas] => Array
                (
                    [0] => 523
                    [1] => 0
                    [2] => 500
                    [3] => 0
                    [4] => 0
                    [5] => 0
                    [6] => 0
                    [7] => 0
                    [8] => 0
                    [9] => 0
                    [10] => 0
                    [11] => 0
                    [12] => 0
                    [13] => 0
                    [14] => 0
                    [15] => 0
                    [16] => 0
                    [17] => 0
                    [18] => 0
                    [19] => 0
                    [20] => 0
                    [21] => 0
                    [22] => 0
                    [23] => 0
                    [24] => 0
                    [25] => 0
                    [26] => 0
                    [27] => 0
                    [28] => 0
                    [29] => 0
                    [30] => 0
                    [31] => 0
                )

            [stn_favg] => Array
                (
                    [0] => 0
                    [1] => 0
                    [2] => 0
                    [3] => 0
                    [4] => 0
                    [5] => 0
                    [6] => 0
                    [7] => 0
                    [8] => 0
                    [9] => 0
                    [10] => 0
                    [11] => 0
                    [12] => 0
                    [13] => 0
                    [14] => 0
                    [15] => 0
                    [16] => 0
                    [17] => 0
                    [18] => 0
                    [19] => 0
                    [20] => 0
                    [21] => 0
                    [22] => 0
                    [23] => 0
                    [24] => 0
                    [25] => 0
                    [26] => 0
                    [27] => 0
                    [28] => 0
                    [29] => 0
                    [30] => 0
                    [31] => 0
                )

            [snames] => Array
                (
                    [0] => Gefäße (Terrasse)
                    [1] => Gefäße (Wiese; Stellplatz)
                    [2] => Gefäße (Beet)
                    [3] => Pflanztrog (Garage)
                    [4] => Brunnen
                    [5] => Beet (Küche)
                    [6] => Beet (Schuppen, rechts)
                    [7] => Beet (Straße)
                    [8] => Beet (links)
                    [9] => Beet (rechts)
                    [10] => Tomatenhaus
                    [11] => Hochbeet (links)
                    [12] => Hochbeet (mitte)
                    [13] => Hochbeet (rechts)
                    [14] => Hochbeet (Wiese)
                    [15] => Rasen (Schuppen)
                    [16] => Rasen (groß, links)
                    [17] => Rasen (groß, rechts)
                    [18] => Rasen (klein, links)
                    [19] => Rasen (klein, mitte)
                    [20] => Rasen (klein, rechts)
                    [21] => S22
                    [22] => S23
                    [23] => S24
                    [24] => Gefäße (Haustür)
                    [25] => Gefäße (Küche, Vorplatz)
                    [26] => Beet (Trockenmauer)
                    [27] => Hochbeet (Küche)
                    [28] => Rasen (neben Hochbeet)
                    [29] => S30
                    [30] => S31
                    [31] => S32
                )

            [maxlen] => 32
        )

)

 */
        /*
        17.11.2024, 17:12:49 | RetriveConfiguration | program_data=Array
        (
            [nprogs] => 6
            [nboards] => 4
            [mnp] => 40
            [mnst] => 4
            [pnsize] => 32
            [pd] => Array
                (
                    [0] => Array
                        (
                            [0] => 115
                            [1] => 0
                            [2] => 1
                            [3] => Array ( [0] => 420 [1] => -1 [2] => -1 [3] => -1)
                            [4] => Array ( [0] => 0 [1] => 0 [2] => 0 [3] => 0 [4] => 0 [5] => 600 [6] => 600 [7] => 600 [8] => 600 [9] => 0 [10] => 0 ... )
                            [5] => Beete
                            [6] => Array ( [0] => 0 [1] => 33 [2] => 415)
                        )
                )

        )


        [[flag, days0, days1, [start0, start1, start2, start3], [dur0, dur1, dur2...], name, [endr, from, to]]]
        ● flag: a bit field storing program flags
            o bit 0: program enable 'en' bit (1: enabled; 0: disabled)
            o bit 1: use weather adjustment 'uwt' bit (1: yes; 0: no)
            o bit 2-3: odd/even restriction (0: none; 1: odd-day restriction; 2: even-day restriction; 3: undefined)
            o bit 4-5: program schedule type (0: weekday; 1: undefined; 2: undefined; 3: interval day)
            o bit 6: start time type (0: repeating type; 1: fixed time type)
            o bit 7: enable date range (0: do not use date range; 1: use date range)
        ● days0/days1:
            o If(flag.bits[4..5]==0), this is a weekday schedule:
                ▪ days0.bits[0..6] store the binary selection bit from Monday to Sunday; days1 is unused.
                For example, days0=127 means the program runs every day of the week; days0=21 (0b0010101) means the program runs on Monday, Wednesday, Friday every week.
            o If(flag.bits[4..5]==3), this is an interval day schedule:
                ▪ days1 stores the interval day, days0 stores the remainder (i.e. starting in day).
                For example, days1=3 and days0=0 means the program runs every 3 days, starting from today.
        ● start0/start1/start2/start3 (a value of -1 means the start time is disabled):
            o Starttimes support using sunrise or sunset with a maximum offset value of +/- 4 hours in minute granularity:
                ▪ If bits 13 and 14 are both cleared (i.e. 0), this defines the start time in terms of minutes since midnight.
                ▪ If bit 13 is 1, this defines sunset time as start time. Similarly, if bit 14 is 1, this defines sunrise time.
                ▪ If either bit 13 or 14 is 1, the remaining 12 bits then define the offset. Specifically, bit 12 is the sign (if true, it is negative); the absolute value of the offset is the remaining 11 bits (i.e. start_time&0x7FF).
            o If(flag.bit6==1), this is a fixed starttime type:
                ▪ start0, start1, start2, start3 store up to 4 fixed start times (minutes from midnight). Acceptable range is -1 to 1440. If set to -1, the
        specific start time is disabled.
            o If(flag.bit6==0), this is a repeating starttime type:
                ▪ start0 stores the first start time (minutes from midnight), start1 stores the repeat count, start2 stores the interval time (in minutes); start3 is unused. For example, [480,5,120,0] means: start at 8:00 AM, repeat every 2 hours (120 minutes) for 5 times.
        ● dur0,dur1...: The water time (in seconds) of each station. 0 means the station will not run. The number of elements here must match the number of stations. Unlike the previous firmwares, this firmware allows full second-level precision water time from 0 to 64800 seconds (18 hours). The two special values are: 1) 65534 represents sunrise to sunset duration; 2) 65535 represents sunset to sunrise duration.
        ● name: Program name
        ● [endr,from,to]: daterange parameters, inclusive on both 'from' and 'to'.
            o endr: daterange enable (the same value as bit 7 of flag).
            o from: integer value storing the start date. It's encoded as (month<<5)+day. For example, Feb 3 is encoded as (2<<5)+3=67. The default value is 33 (Jan 1).
            o to: the end date (encoded the same way as 'from'). The default value is 415 (Dec 31). Note that 'from' can be either smaller than, larger than, or equal to 'to'. If 'from' is larger than 'to', the range goes from 'from' to the 'to' date of the following year.

         */
