<?php

declare(strict_types=1);

/**
 * StiebelWPL – Stiebel Eltron Wärmepumpe (WPM 3) über ISG web per Modbus TCP
 *
 * Registerbasis: Stiebel Eltron "Software-Dokumentation Modbus TCP/IP" (ISG web)
 *  - Block 1 Systemwerte        (Input Register,   ab 501)
 *  - Block 2 Systemparameter    (Holding Register, ab 1501)
 *  - Block 3 Systemstatus       (Input Register,   ab 2501)
 *  - Block 4 Energetische Daten (Input Register,   ab 3501)
 *  - Block 5 SG Ready Vorgaben  (Holding Register, ab 4001)
 *  - Block 6 SG Ready Infos     (Input Register,   ab 5001)
 */
class StiebelWPL extends IPSModule
{
    private const WEBHOOK = '/hook/stiebelwpl';
    private const GUID_WEBHOOK_CONTROL = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';

    // Ersatzwert des ISG für "Objekt nicht verfügbar"
    private const NA_RAW = 0x8000;

    // Schreibbare Holding-Register: Ident => [Register, Kodierung]
    private const WRITE_MAP = [
        'Betriebsart'    => [1501, 'raw'],
        'HK1Komfort'     => [1502, 't2'],
        'HK1Eco'         => [1503, 't2'],
        'Heizkurve'      => [1504, 't7'],
        'WWKomfort'      => [1510, 't2'],
        'WWEco'          => [1511, 't2'],
        'KuehlVLSoll'    => [1514, 't2'],
        'KuehlHysterese' => [1515, 't2'],
        'KuehlRaumSoll'  => [1516, 't2'],
        'SGAktiv'        => [4001, 'bool'],
        'SGIn1'          => [4002, 'bool'],
        'SGIn2'          => [4003, 'bool']
    ];

    // Werte, die das ISG als "nicht verfügbar" melden kann -> im Dashboard nur zeigen,
    // wenn sie mindestens einmal geliefert wurden (sonst stünde dort fälschlich 0)
    private const OPTIONAL_VALUES = [
        'Raumtemperatur', 'RaumtemperaturSoll', 'Raumfeuchte', 'Taupunkt', 'TaupunktReserve',
        'Aussentemperatur', 'HK1Ist', 'HK1Soll', 'HK2Ist', 'HK2Soll', 'VorlaufIst', 'RuecklaufIst',
        'WPVorlauf', 'WPRuecklauf', 'PufferIst', 'PufferSoll', 'WWIst', 'WWSoll',
        'Heissgas', 'DruckND', 'DruckMD', 'DruckHD', 'Volumenstrom',
        'KuehlIst', 'KuehlSoll', 'KuehlVLSoll', 'KuehlHysterese', 'KuehlRaumSoll',
        'HK1Komfort', 'HK1Eco', 'Heizkurve', 'WWKomfort', 'WWEco'
    ];

    /** @var array<string,bool> im aktuellen Poll gelieferte Idents */
    private $availNow = [];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 502);
        $this->RegisterPropertyInteger('Interval', 30);
        $this->RegisterPropertyBoolean('EnableWrite', true);
        $this->RegisterPropertyBoolean('EnableCooling', true);
        $this->RegisterPropertyBoolean('EnableEnergy', true);
        $this->RegisterPropertyBoolean('EnableSGReady', true);
        $this->RegisterPropertyBoolean('EnableHK2', false);
        $this->RegisterPropertyBoolean('EnableDashboard', true);
        $this->RegisterPropertyBoolean('EnableArchive', true);
        $this->RegisterPropertyString('PinCode', '');

        // Adress-Offset: manche ISG-Firmwares erwarten Registernummer-1 (Modbus-Konvention),
        // manche die Registernummer direkt. Wird automatisch erkannt. -99 = noch unbekannt.
        $this->RegisterAttributeInteger('AddrOffset', -99);
        // Idents, die schon mindestens einmal einen echten Wert geliefert haben
        $this->RegisterAttributeString('AvailIdents', '[]');

        $this->RegisterTimer('Update', 0, 'SWPL_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        $this->RegisterProfiles();
        $this->MaintainVariables();
        $this->SetupArchive();

        if ($this->ReadPropertyBoolean('EnableDashboard')) {
            $this->RegisterHook(self::WEBHOOK);
        }

        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            $this->SetTimerInterval('Update', 0);
            $this->SetStatus(104);
            return;
        }

        $this->SetTimerInterval('Update', $this->ReadPropertyInteger('Interval') * 1000);
        $this->Update();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    // =====================================================================
    // Öffentliche Funktionen
    // =====================================================================

    /**
     * Liest alle Register vom ISG und aktualisiert die Statusvariablen.
     */
    public function Update(): bool
    {
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            return false;
        }

        $sock = $this->mbConnect();
        if ($sock === false) {
            $this->SetStatus(201);
            return false;
        }

        $this->availNow = [];

        try {
            $off = $this->detectOffset($sock);

            // Block 1: Systemwerte 501..548
            $b1 = $this->mbRead($sock, 4, 501 + $off, 48);
            // Block 1b: Raumwerte je Heiz-/Kühlkreis 584..608 (Fallback, je nach Regler)
            $b1b = $this->mbRead($sock, 4, 584 + $off, 25);
            // Block 2: Systemparameter 1501..1516
            $b2 = $this->mbRead($sock, 3, 1501 + $off, 16);
            // Block 3: Systemstatus 2501..2507
            $b3 = $this->mbRead($sock, 4, 2501 + $off, 7);

            $b4 = null;
            if ($this->ReadPropertyBoolean('EnableEnergy')) {
                // Block 4: Energiedaten 3501..3548
                $b4 = $this->mbRead($sock, 4, 3501 + $off, 48);
            }

            $b5 = null;
            $b6 = null;
            if ($this->ReadPropertyBoolean('EnableSGReady')) {
                $b5 = $this->mbRead($sock, 3, 4001 + $off, 3);
                $b6 = $this->mbRead($sock, 4, 5001 + $off, 2);
            }
        } finally {
            fclose($sock);
        }

        if ($b1 === null && $b3 === null) {
            $this->SetStatus(201);
            return false;
        }

        $this->SetStatus(102);
        $this->parseBlock1($b1, $b1b);
        $this->parseBlock2($b2);
        $this->parseBlock3($b3);
        $this->parseBlock4($b4);
        $this->parseSGReady($b5, $b6);

        $this->SetValueSafe('LastUpdate', time());

        // Verfügbare Idents dauerhaft merken (fürs Dashboard: nie gelieferte Werte -> "–")
        $avail = json_decode($this->ReadAttributeString('AvailIdents'), true);
        if (!is_array($avail)) {
            $avail = [];
        }
        $merged = array_values(array_unique(array_merge($avail, array_keys($this->availNow))));
        if (count($merged) !== count($avail)) {
            $this->WriteAttributeString('AvailIdents', json_encode($merged));
        }
        return true;
    }

    /**
     * Verbindungstest für die Instanzkonfiguration.
     */
    public function TestConnection(): string
    {
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            return 'Bitte zuerst die IP-Adresse des ISG eintragen und speichern.';
        }

        $sock = $this->mbConnect();
        if ($sock === false) {
            return "Keine Verbindung zu {$host}:" . $this->ReadPropertyInteger('Port') . ' möglich.';
        }

        try {
            $this->WriteAttributeInteger('AddrOffset', -99); // Neuerkennung erzwingen
            $off = $this->detectOffset($sock);
            $regler = $this->mbRead($sock, 4, 5002 + $off, 1);
            $aussen = $this->mbRead($sock, 4, 507 + $off, 1);
        } finally {
            fclose($sock);
        }

        $reglerName = 'unbekannt';
        if ($regler !== null) {
            $reglerName = $this->reglerName($regler[0]) . ' (' . $regler[0] . ')';
        }
        $aussenTxt = 'n/v';
        if ($aussen !== null) {
            $t = $this->convVal($aussen[0], 't2');
            $aussenTxt = ($t === null) ? 'n/v' : number_format($t, 1, ',', '') . ' °C';
        }

        return "Verbindung OK.\nRegler: {$reglerName}\nAdress-Offset: {$off}\nAußentemperatur: {$aussenTxt}";
    }

    /**
     * Aktionen der schreibbaren Variablen (Betriebsart, Sollwerte, SG Ready).
     */
    public function RequestAction($Ident, $Value)
    {
        if (!isset(self::WRITE_MAP[$Ident])) {
            throw new Exception('Unbekannter Ident: ' . $Ident);
        }
        if (!$this->ReadPropertyBoolean('EnableWrite')) {
            $this->LogMessage('Schreibzugriff ist in der Instanzkonfiguration deaktiviert.', KL_WARNING);
            return;
        }

        [$register, $coding] = self::WRITE_MAP[$Ident];

        switch ($coding) {
            case 't2':
                $raw = (int) round(((float) $Value) * 10);
                break;
            case 't7':
                $raw = (int) round(((float) $Value) * 100);
                break;
            case 'bool':
                $raw = $Value ? 1 : 0;
                break;
            default:
                $raw = (int) $Value;
        }

        $sock = $this->mbConnect();
        if ($sock === false) {
            $this->SetStatus(201);
            throw new Exception('Keine Verbindung zum ISG.');
        }

        try {
            $off = $this->detectOffset($sock);
            $ok = $this->mbWrite($sock, $register + $off, $raw);
        } finally {
            fclose($sock);
        }

        if (!$ok) {
            throw new Exception('Schreiben auf Register ' . $register . ' fehlgeschlagen.');
        }

        // Lokale Variable sofort nachziehen, echter Wert kommt beim nächsten Poll
        switch ($coding) {
            case 'bool':
                $this->SetValueSafe($Ident, (bool) $Value);
                break;
            case 'raw':
                $this->SetValueSafe($Ident, (int) $Value);
                break;
            default:
                $this->SetValueSafe($Ident, (float) $Value);
        }
    }

    // =====================================================================
    // WebHook (Dashboard)
    // =====================================================================

    protected function ProcessHookData()
    {
        if (!$this->ReadPropertyBoolean('EnableDashboard')) {
            http_response_code(404);
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $payload = json_decode(file_get_contents('php://input'), true);
            $ident = (string) ($payload['ident'] ?? '');
            if (!isset(self::WRITE_MAP[$ident])) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'invalid ident']);
                return;
            }
            $pin = $this->ReadPropertyString('PinCode');
            if ($pin !== '' && (string) ($payload['pin'] ?? '') !== $pin) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => 'PIN']);
                return;
            }
            $value = $payload['value'] ?? null;
            try {
                IPS_RequestAction($this->InstanceID, $ident, $value);
                header('Content-Type: application/json');
                echo json_encode(['ok' => true]);
            } catch (Throwable $e) {
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            }
            return;
        }

        if (isset($_GET['data'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($this->collectData());
            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/dashboard.html');
    }

    private function collectData(): array
    {
        $idents = [
            'Betriebsart', 'StatusText', 'Verdichter', 'Heizbetrieb', 'WWBetrieb', 'Kuehlbetrieb',
            'Sommerbetrieb', 'Abtauen', 'HK1Pumpe', 'NHZ', 'EVUFreigabe', 'Fehler', 'Fehlernummer',
            'Aussentemperatur', 'Raumtemperatur', 'RaumtemperaturSoll', 'Raumfeuchte', 'Taupunkt',
            'TaupunktReserve', 'HK1Ist', 'HK1Soll', 'HK2Ist', 'HK2Soll', 'VorlaufIst', 'RuecklaufIst',
            'WPVorlauf', 'WPRuecklauf', 'PufferIst', 'PufferSoll', 'WWIst', 'WWSoll',
            'Heissgas', 'DruckND', 'DruckMD', 'DruckHD', 'Volumenstrom',
            'KuehlIst', 'KuehlSoll', 'KuehlVLSoll', 'KuehlHysterese', 'KuehlRaumSoll',
            'HK1Komfort', 'HK1Eco', 'Heizkurve', 'WWKomfort', 'WWEco',
            'WMHeizenTag', 'WMHeizenSum', 'WMWWTag', 'WMWWSum',
            'LAHeizenTag', 'LAHeizenSum', 'LAWWTag', 'LAWWSum',
            'NHZHeizenSum', 'NHZWWSum',
            'COPHeizenTag', 'COPWWTag', 'COPHeizenGesamt', 'COPWWGesamt',
            'LZHeizen', 'LZWW', 'LZKuehlen', 'LZNHZ',
            'SGZustand', 'SGAktiv', 'SGIn1', 'SGIn2',
            'Regler', 'LastUpdate'
        ];

        $avail = json_decode($this->ReadAttributeString('AvailIdents'), true);
        if (!is_array($avail)) {
            $avail = [];
        }
        $availSet = array_flip($avail);
        $optionalSet = array_flip(self::OPTIONAL_VALUES);

        $out = [
            'writeEnabled' => $this->ReadPropertyBoolean('EnableWrite'),
            'pinRequired'  => $this->ReadPropertyString('PinCode') !== '',
            'cooling'      => $this->ReadPropertyBoolean('EnableCooling'),
            'energy'       => $this->ReadPropertyBoolean('EnableEnergy'),
            'sgready'      => $this->ReadPropertyBoolean('EnableSGReady'),
            'hk2'          => $this->ReadPropertyBoolean('EnableHK2')
        ];
        foreach ($idents as $ident) {
            // Nie gelieferte optionale Werte weglassen -> Dashboard zeigt "–" statt 0
            if (count($avail) > 0 && isset($optionalSet[$ident]) && !isset($availSet[$ident])) {
                continue;
            }
            $vid = @$this->GetIDForIdent($ident);
            if ($vid !== false && $vid > 0) {
                $out[$ident] = GetValue($vid);
            }
        }
        return $out;
    }

    // =====================================================================
    // Register-Parsing
    // =====================================================================

    private function parseBlock1(?array $b, ?array $bb = null): void
    {
        if ($b === null) {
            return;
        }
        $g = fn (int $reg) => $b[$reg - 501] ?? self::NA_RAW;
        // Raumwerte je Heizkreis (584 ff.), liefert je nach Regler die Werte statt FE7/FEK
        $gb = fn (int $reg) => ($bb !== null) ? ($bb[$reg - 584] ?? self::NA_RAW) : self::NA_RAW;

        // Raumklima: FEK bevorzugen (liefert Feuchte/Taupunkt), sonst FE7, sonst Raumwerte HK 1
        $raumIst = $this->convVal($g(503), 't2') ?? $this->convVal($g(501), 't2') ?? $this->convVal($gb(584), 't2');
        $raumSoll = $this->convVal($g(504), 't2') ?? $this->convVal($g(502), 't2') ?? $this->convVal($gb(585), 't2');
        $this->SetValueSafe('Raumtemperatur', $raumIst);
        $this->SetValueSafe('RaumtemperaturSoll', $raumSoll);
        $this->SetValueSafe('Raumfeuchte', $this->convVal($g(505), 't2') ?? $this->convVal($gb(586), 't2'));
        $taupunkt = $this->convVal($g(506), 't2') ?? $this->convVal($gb(587), 't2');
        $this->SetValueSafe('Taupunkt', $taupunkt);

        $this->SetValueSafe('Aussentemperatur', $this->convVal($g(507), 't2'));
        $this->SetValueSafe('HK1Ist', $this->convVal($g(508), 't2'));
        // 510 = Solltemperatur HK1 (WPMsystem/WPM 3), 509 nur WPM 3i
        $hk1Soll = $this->convVal($g(510), 't2') ?? $this->convVal($g(509), 't2');
        $this->SetValueSafe('HK1Soll', $hk1Soll);

        if ($this->ReadPropertyBoolean('EnableHK2')) {
            $this->SetValueSafe('HK2Ist', $this->convVal($g(511), 't2'));
            $this->SetValueSafe('HK2Soll', $this->convVal($g(512), 't2'));
        }

        $vl = $this->convVal($g(515), 't2');
        $this->SetValueSafe('VorlaufIst', $vl);
        $this->SetValueSafe('RuecklaufIst', $this->convVal($g(516), 't2'));
        $this->SetValueSafe('PufferIst', $this->convVal($g(518), 't2'));
        $this->SetValueSafe('PufferSoll', $this->convVal($g(519), 't2'));
        $this->SetValueSafe('WWIst', $this->convVal($g(522), 't2'));
        $this->SetValueSafe('WWSoll', $this->convVal($g(523), 't2'));

        if ($this->ReadPropertyBoolean('EnableCooling')) {
            $this->SetValueSafe('KuehlIst', $this->convVal($g(526), 't2'));
            $this->SetValueSafe('KuehlSoll', $this->convVal($g(527), 't2'));
        }

        // Wärmepumpe 1 Prozessdaten
        $wpVl = $this->convVal($g(543), 't2');
        $wpRl = $this->convVal($g(542), 't2');
        $this->SetValueSafe('WPVorlauf', $wpVl);
        $this->SetValueSafe('WPRuecklauf', $wpRl);
        $this->SetValueSafe('Heissgas', $this->convVal($g(544), 't2'));
        $this->SetValueSafe('DruckND', $this->convVal($g(545), 't7'));
        $this->SetValueSafe('DruckMD', $this->convVal($g(546), 't7'));
        $this->SetValueSafe('DruckHD', $this->convVal($g(547), 't7'));
        $this->SetValueSafe('Volumenstrom', $this->convVal($g(548), 't2'));

        // Taupunkt-Reserve: Abstand Vorlauf zu Taupunkt (relevant bei Deckenkühlung)
        if ($taupunkt !== null && $vl !== null) {
            $this->SetValueSafe('TaupunktReserve', round($vl - $taupunkt, 1));
        }
    }

    private function parseBlock2(?array $b): void
    {
        if ($b === null) {
            return;
        }
        $g = fn (int $reg) => $b[$reg - 1501] ?? self::NA_RAW;

        $ba = $g(1501);
        if ($ba !== self::NA_RAW) {
            $this->SetValueSafe('Betriebsart', $ba & 0xFF);
        }
        $this->SetValueSafe('HK1Komfort', $this->convVal($g(1502), 't2'));
        $this->SetValueSafe('HK1Eco', $this->convVal($g(1503), 't2'));
        $this->SetValueSafe('Heizkurve', $this->convVal($g(1504), 't7'));
        $this->SetValueSafe('WWKomfort', $this->convVal($g(1510), 't2'));
        $this->SetValueSafe('WWEco', $this->convVal($g(1511), 't2'));

        if ($this->ReadPropertyBoolean('EnableCooling')) {
            $this->SetValueSafe('KuehlVLSoll', $this->convVal($g(1514), 't2'));
            $this->SetValueSafe('KuehlHysterese', $this->convVal($g(1515), 't2'));
            $this->SetValueSafe('KuehlRaumSoll', $this->convVal($g(1516), 't2'));
        }
    }

    private function parseBlock3(?array $b): void
    {
        if ($b === null) {
            return;
        }
        $g = fn (int $reg) => $b[$reg - 2501] ?? 0;

        $status = $g(2501);
        $bit = fn (int $n) => (bool) (($status >> $n) & 1);

        $this->SetValueSafe('HK1Pumpe', $bit(0));
        $this->SetValueSafe('NHZ', $bit(3));
        $this->SetValueSafe('Heizbetrieb', $bit(4));
        $this->SetValueSafe('WWBetrieb', $bit(5));
        $this->SetValueSafe('Verdichter', $bit(6));
        $this->SetValueSafe('Sommerbetrieb', $bit(7));
        $this->SetValueSafe('Kuehlbetrieb', $bit(8));
        $this->SetValueSafe('Abtauen', $bit(9));

        $aktiv = [];
        if ($bit(4)) {
            $aktiv[] = 'Heizen';
        }
        if ($bit(5)) {
            $aktiv[] = 'Warmwasser';
        }
        if ($bit(8)) {
            $aktiv[] = 'Kühlen';
        }
        if ($bit(9)) {
            $aktiv[] = 'Abtauen';
        }
        if ($bit(3)) {
            $aktiv[] = 'NHZ';
        }
        if (empty($aktiv)) {
            $aktiv[] = $bit(7) ? 'Sommerbetrieb' : 'Bereit';
        }
        if (!$bit(6) && ($bit(4) || $bit(8))) {
            // Kreis aktiv, aber Verdichter aus -> nur Umwälzung
            $aktiv[] = '(Verdichter aus)';
        }
        $this->SetValueSafe('StatusText', implode(' + ', $aktiv));

        $this->SetValueSafe('EVUFreigabe', (bool) ($g(2502) & 1));
        $this->SetValueSafe('Fehler', ($g(2504) & 0xFF) === 1);
        $this->SetValueSafe('Fehlernummer', $g(2507));
    }

    private function parseBlock4(?array $b): void
    {
        if ($b === null || !$this->ReadPropertyBoolean('EnableEnergy')) {
            return;
        }
        $g = fn (int $reg) => $b[$reg - 3501] ?? 0;
        // Summenwerte: kWh-Anteil (0..999) + MWh-Anteil -> Gesamt in MWh
        $sum = fn (int $regKwh, int $regMwh) => round($g($regMwh) + $g($regKwh) / 1000, 3);

        $wmHeizTag = (float) $g(3501);
        $wmWWTag = (float) $g(3504);
        $laHeizTag = (float) $g(3511);
        $laWWTag = (float) $g(3514);

        $wmHeizSum = $sum(3502, 3503);
        $wmWWSum = $sum(3505, 3506);
        $laHeizSum = $sum(3512, 3513);
        $laWWSum = $sum(3515, 3516);

        $this->SetValueSafe('WMHeizenTag', $wmHeizTag);
        $this->SetValueSafe('WMHeizenSum', $wmHeizSum);
        $this->SetValueSafe('WMWWTag', $wmWWTag);
        $this->SetValueSafe('WMWWSum', $wmWWSum);
        $this->SetValueSafe('LAHeizenTag', $laHeizTag);
        $this->SetValueSafe('LAHeizenSum', $laHeizSum);
        $this->SetValueSafe('LAWWTag', $laWWTag);
        $this->SetValueSafe('LAWWSum', $laWWSum);
        $this->SetValueSafe('NHZHeizenSum', $sum(3507, 3508));
        $this->SetValueSafe('NHZWWSum', $sum(3509, 3510));

        // Arbeitszahlen (COP): Wärmemenge / Leistungsaufnahme
        $cop = fn (float $wm, float $la) => ($la > 0) ? round($wm / $la, 2) : 0.0;
        $this->SetValueSafe('COPHeizenTag', $cop($wmHeizTag, $laHeizTag));
        $this->SetValueSafe('COPWWTag', $cop($wmWWTag, $laWWTag));
        $this->SetValueSafe('COPHeizenGesamt', $cop($wmHeizSum, $laHeizSum));
        $this->SetValueSafe('COPWWGesamt', $cop($wmWWSum, $laWWSum));

        // Laufzeiten (WP 1)
        $this->SetValueSafe('LZHeizen', $g(3539));
        $this->SetValueSafe('LZWW', $g(3542));
        $this->SetValueSafe('LZKuehlen', $g(3545));
        $this->SetValueSafe('LZNHZ', $g(3548));
    }

    private function parseSGReady(?array $b5, ?array $b6): void
    {
        if (!$this->ReadPropertyBoolean('EnableSGReady')) {
            return;
        }
        if ($b5 !== null) {
            $this->SetValueSafe('SGAktiv', (bool) ($b5[0] & 1));
            $this->SetValueSafe('SGIn1', (bool) ($b5[1] & 1));
            $this->SetValueSafe('SGIn2', (bool) ($b5[2] & 1));
        }
        if ($b6 !== null) {
            $zustand = $b6[0];
            if ($zustand >= 1 && $zustand <= 4) {
                $this->SetValueSafe('SGZustand', $zustand);
            }
            $this->SetValueSafe('Regler', $this->reglerName($b6[1]));
        }
    }

    private function reglerName(int $code): string
    {
        switch ($code) {
            case 390:
                return 'WPM 3';
            case 391:
                return 'WPM 3i';
            case 449:
                return 'WPMsystem';
            case 103:
            case 104:
                return 'LWZ/LWA';
            default:
                return 'Unbekannt (' . $code . ')';
        }
    }

    // =====================================================================
    // Modbus TCP
    // =====================================================================

    /** @return resource|false */
    private function mbConnect()
    {
        $host = trim($this->ReadPropertyString('Host'));
        $port = $this->ReadPropertyInteger('Port');
        $sock = @fsockopen($host, $port, $errno, $errstr, 3);
        if ($sock === false) {
            $this->SendDebug('Modbus', "Connect fehlgeschlagen: {$errstr} ({$errno})", 0);
            return false;
        }
        stream_set_timeout($sock, 3);
        return $sock;
    }

    /**
     * Liest $qty Register ab Adresse $addr. FC 3 = Holding, FC 4 = Input.
     * Rückgabe: Array der Rohwerte (unsigned 16 Bit) oder null bei Fehler.
     */
    private function mbRead($sock, int $fc, int $addr, int $qty): ?array
    {
        $tid = random_int(1, 0xFFFF);
        $pdu = pack('Cnn', $fc, $addr, $qty);
        $adu = pack('nnnC', $tid, 0, strlen($pdu) + 1, 1) . $pdu;

        if (@fwrite($sock, $adu) === false) {
            $this->SendDebug('Modbus', 'Schreibfehler auf Socket', 0);
            return null;
        }

        $hdr = $this->readBytes($sock, 7);
        if ($hdr === null) {
            $this->SendDebug('Modbus', "Timeout (FC{$fc} Adr {$addr})", 0);
            return null;
        }
        $h = unpack('ntid/nproto/nlen/Cunit', $hdr);
        $body = $this->readBytes($sock, $h['len'] - 1);
        if ($body === null || strlen($body) < 2) {
            return null;
        }

        $rfc = ord($body[0]);
        if ($rfc & 0x80) {
            $this->SendDebug('Modbus', "Exception Code " . ord($body[1]) . " (FC{$fc} Adr {$addr})", 0);
            return null;
        }

        $count = ord($body[1]);
        $data = substr($body, 2, $count);
        if (strlen($data) < $count) {
            return null;
        }
        return array_values(unpack('n*', $data));
    }

    /**
     * Schreibt einen Wert in ein Holding-Register (FC 6).
     */
    private function mbWrite($sock, int $addr, int $value): bool
    {
        $tid = random_int(1, 0xFFFF);
        $pdu = pack('Cnn', 6, $addr, $value & 0xFFFF);
        $adu = pack('nnnC', $tid, 0, strlen($pdu) + 1, 1) . $pdu;

        if (@fwrite($sock, $adu) === false) {
            return false;
        }
        $hdr = $this->readBytes($sock, 7);
        if ($hdr === null) {
            return false;
        }
        $h = unpack('ntid/nproto/nlen/Cunit', $hdr);
        $body = $this->readBytes($sock, $h['len'] - 1);
        if ($body === null || strlen($body) < 1) {
            return false;
        }
        if (ord($body[0]) & 0x80) {
            $this->SendDebug('Modbus', 'Write Exception Code ' . ord($body[1] ?? "\0") . " (Adr {$addr})", 0);
            return false;
        }
        return true;
    }

    private function readBytes($sock, int $len): ?string
    {
        $buf = '';
        while (strlen($buf) < $len) {
            $chunk = fread($sock, $len - strlen($buf));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($sock);
                if ($chunk === false || $meta['timed_out'] || feof($sock)) {
                    return null;
                }
            } else {
                $buf .= $chunk;
            }
        }
        return $buf;
    }

    /**
     * Erkennt automatisch, ob das ISG die Registernummer 1:1 oder um -1 versetzt erwartet.
     * Prüfregister: 5002 (Reglerkennung) muss einen bekannten Code liefern.
     */
    private function detectOffset($sock): int
    {
        $cached = $this->ReadAttributeInteger('AddrOffset');
        if ($cached === -1 || $cached === 0) {
            return $cached;
        }

        foreach ([-1, 0] as $off) {
            $r = $this->mbRead($sock, 4, 5002 + $off, 1);
            if ($r !== null && in_array($r[0], [103, 104, 390, 391, 449], true)) {
                $this->WriteAttributeInteger('AddrOffset', $off);
                $this->SendDebug('Modbus', "Adress-Offset erkannt: {$off} (Regler {$r[0]})", 0);
                return $off;
            }
        }

        $this->SendDebug('Modbus', 'Adress-Offset nicht erkennbar, verwende -1 (Modbus-Konvention)', 0);
        return -1;
    }

    /**
     * Rohwert gemäß Stiebel-Datentyp umrechnen.
     *  t2 = signed, Faktor 0,1 | t7 = signed, Faktor 0,01
     * 0x8000 = "nicht verfügbar" -> null
     */
    private function convVal(int $raw, string $type): ?float
    {
        if ($raw === self::NA_RAW && ($type === 't2' || $type === 't7')) {
            return null;
        }
        $signed = ($raw > 0x7FFF) ? $raw - 0x10000 : $raw;
        switch ($type) {
            case 't2':
                return $signed / 10;
            case 't7':
                return $signed / 100;
            default:
                return (float) $raw;
        }
    }

    private function SetValueSafe(string $ident, $value): void
    {
        if ($value === null) {
            return;
        }
        $this->availNow[$ident] = true;
        $vid = @$this->GetIDForIdent($ident);
        if ($vid === false || $vid <= 0) {
            return;
        }
        if (GetValue($vid) != $value) {
            SetValue($vid, $value);
        }
    }

    // =====================================================================
    // Variablen und Profile
    // =====================================================================

    private function MaintainVariables(): void
    {
        $w = $this->ReadPropertyBoolean('EnableWrite');
        $cool = $this->ReadPropertyBoolean('EnableCooling');
        $energy = $this->ReadPropertyBoolean('EnableEnergy');
        $sg = $this->ReadPropertyBoolean('EnableSGReady');
        $hk2 = $this->ReadPropertyBoolean('EnableHK2');

        // [Ident, Name, Typ (0=bool,1=int,2=float,3=string), Profil, Position, anlegen?, Aktion?]
        $vars = [
            ['Betriebsart', 'Betriebsart', 1, 'SWPL.Betriebsart', 10, true, $w],
            ['StatusText', 'Status', 3, '', 20, true, false],
            ['Verdichter', 'Verdichter', 0, 'SWPL.Aktiv', 21, true, false],
            ['Heizbetrieb', 'Heizbetrieb', 0, 'SWPL.Aktiv', 22, true, false],
            ['WWBetrieb', 'Warmwasserbetrieb', 0, 'SWPL.Aktiv', 23, true, false],
            ['Kuehlbetrieb', 'Kühlbetrieb', 0, 'SWPL.Aktiv', 24, $cool, false],
            ['Sommerbetrieb', 'Sommerbetrieb', 0, 'SWPL.Aktiv', 25, true, false],
            ['Abtauen', 'Abtaubetrieb', 0, 'SWPL.Aktiv', 26, true, false],
            ['HK1Pumpe', 'Heizkreispumpe 1', 0, 'SWPL.Aktiv', 27, true, false],
            ['NHZ', 'Elektrische Nachheizung', 0, 'SWPL.Aktiv', 28, true, false],
            ['EVUFreigabe', 'EVU-Freigabe', 0, 'SWPL.Aktiv', 29, true, false],
            ['Fehler', 'Störung', 0, '~Alert', 30, true, false],
            ['Fehlernummer', 'Fehlernummer', 1, '', 31, true, false],

            ['Aussentemperatur', 'Außentemperatur', 2, 'SWPL.TempC', 40, true, false],
            ['Raumtemperatur', 'Raumtemperatur', 2, 'SWPL.TempC', 41, true, false],
            ['RaumtemperaturSoll', 'Raumtemperatur Soll', 2, 'SWPL.TempC', 42, true, false],
            ['Raumfeuchte', 'Raumfeuchte', 2, 'SWPL.Feuchte', 43, true, false],
            ['Taupunkt', 'Taupunkttemperatur', 2, 'SWPL.TempC', 44, true, false],
            ['TaupunktReserve', 'Taupunkt-Reserve (Vorlauf)', 2, 'SWPL.TempDiff', 45, $cool, false],

            ['HK1Ist', 'Heizkreis 1 Ist', 2, 'SWPL.TempC', 50, true, false],
            ['HK1Soll', 'Heizkreis 1 Soll', 2, 'SWPL.TempC', 51, true, false],
            ['HK2Ist', 'Heizkreis 2 Ist', 2, 'SWPL.TempC', 52, $hk2, false],
            ['HK2Soll', 'Heizkreis 2 Soll', 2, 'SWPL.TempC', 53, $hk2, false],
            ['VorlaufIst', 'Vorlauf Anlage', 2, 'SWPL.TempC', 54, true, false],
            ['RuecklaufIst', 'Rücklauf Anlage', 2, 'SWPL.TempC', 55, true, false],
            ['WPVorlauf', 'Vorlauf Wärmepumpe', 2, 'SWPL.TempC', 56, true, false],
            ['WPRuecklauf', 'Rücklauf Wärmepumpe', 2, 'SWPL.TempC', 57, true, false],
            ['PufferIst', 'Puffer Ist', 2, 'SWPL.TempC', 58, true, false],
            ['PufferSoll', 'Puffer Soll', 2, 'SWPL.TempC', 59, true, false],
            ['WWIst', 'Warmwasser Ist', 2, 'SWPL.TempC', 60, true, false],
            ['WWSoll', 'Warmwasser Soll', 2, 'SWPL.TempC', 61, true, false],
            ['Heissgas', 'Heißgastemperatur', 2, 'SWPL.TempC', 62, true, false],
            ['DruckND', 'Druck Niederdruck', 2, 'SWPL.Druck', 63, true, false],
            ['DruckMD', 'Druck Mitteldruck', 2, 'SWPL.Druck', 64, true, false],
            ['DruckHD', 'Druck Hochdruck', 2, 'SWPL.Druck', 65, true, false],
            ['Volumenstrom', 'Volumenstrom', 2, 'SWPL.Durchfluss', 66, true, false],

            ['KuehlIst', 'Kühlen Ist (Fläche)', 2, 'SWPL.TempC', 80, $cool, false],
            ['KuehlSoll', 'Kühlen Soll (Fläche)', 2, 'SWPL.TempC', 81, $cool, false],
            ['KuehlVLSoll', 'Kühlen Vorlauf-Soll', 2, 'SWPL.TempKuehlVL', 82, $cool, $cool && $w],
            ['KuehlHysterese', 'Kühlen Hysterese', 2, 'SWPL.Hysterese', 83, $cool, $cool && $w],
            ['KuehlRaumSoll', 'Grenze Kühlen (Außentemperatur)', 2, 'SWPL.TempGrenzeKuehl', 84, $cool, $cool && $w],

            ['HK1Komfort', 'Heizen Komfort-Temperatur', 2, 'SWPL.TempHK', 100, true, $w],
            ['HK1Eco', 'Heizen ECO-Temperatur', 2, 'SWPL.TempHK', 101, true, $w],
            ['Heizkurve', 'Heizkurve Steigung', 2, 'SWPL.Heizkurve', 102, true, $w],
            ['WWKomfort', 'Warmwasser Komfort-Temperatur', 2, 'SWPL.TempWW', 110, true, $w],
            ['WWEco', 'Warmwasser ECO-Temperatur', 2, 'SWPL.TempWW', 111, true, $w],

            ['WMHeizenTag', 'Wärmemenge Heizen heute', 2, 'SWPL.kWh', 120, $energy, false],
            ['WMHeizenSum', 'Wärmemenge Heizen gesamt', 2, 'SWPL.MWh', 121, $energy, false],
            ['WMWWTag', 'Wärmemenge Warmwasser heute', 2, 'SWPL.kWh', 122, $energy, false],
            ['WMWWSum', 'Wärmemenge Warmwasser gesamt', 2, 'SWPL.MWh', 123, $energy, false],
            ['LAHeizenTag', 'Stromaufnahme Heizen heute', 2, 'SWPL.kWh', 124, $energy, false],
            ['LAHeizenSum', 'Stromaufnahme Heizen gesamt', 2, 'SWPL.MWh', 125, $energy, false],
            ['LAWWTag', 'Stromaufnahme Warmwasser heute', 2, 'SWPL.kWh', 126, $energy, false],
            ['LAWWSum', 'Stromaufnahme Warmwasser gesamt', 2, 'SWPL.MWh', 127, $energy, false],
            ['NHZHeizenSum', 'NHZ Heizen gesamt', 2, 'SWPL.MWh', 128, $energy, false],
            ['NHZWWSum', 'NHZ Warmwasser gesamt', 2, 'SWPL.MWh', 129, $energy, false],
            ['COPHeizenTag', 'Arbeitszahl Heizen heute', 2, 'SWPL.COP', 130, $energy, false],
            ['COPWWTag', 'Arbeitszahl Warmwasser heute', 2, 'SWPL.COP', 131, $energy, false],
            ['COPHeizenGesamt', 'Arbeitszahl Heizen gesamt', 2, 'SWPL.COP', 132, $energy, false],
            ['COPWWGesamt', 'Arbeitszahl Warmwasser gesamt', 2, 'SWPL.COP', 133, $energy, false],
            ['LZHeizen', 'Laufzeit Verdichter Heizen', 1, 'SWPL.Stunden', 140, $energy, false],
            ['LZWW', 'Laufzeit Verdichter Warmwasser', 1, 'SWPL.Stunden', 141, $energy, false],
            ['LZKuehlen', 'Laufzeit Verdichter Kühlen', 1, 'SWPL.Stunden', 142, $energy && $cool, false],
            ['LZNHZ', 'Laufzeit Nachheizung', 1, 'SWPL.Stunden', 143, $energy, false],

            ['SGZustand', 'SG Ready Betriebszustand', 1, 'SWPL.SGZustand', 160, $sg, false],
            ['SGAktiv', 'SG Ready aktiv', 0, '~Switch', 161, $sg, $sg && $w],
            ['SGIn1', 'SG Ready Eingang 1', 0, '~Switch', 162, $sg, $sg && $w],
            ['SGIn2', 'SG Ready Eingang 2', 0, '~Switch', 163, $sg, $sg && $w],

            ['Regler', 'Reglerkennung', 3, '', 170, true, false],
            ['LastUpdate', 'Letzte Aktualisierung', 1, '~UnixTimestamp', 171, true, false]
        ];

        foreach ($vars as [$ident, $name, $type, $profile, $pos, $keep, $action]) {
            $this->MaintainVariable($ident, $name, $type, $profile, $pos, $keep);
            if ($keep) {
                $this->MaintainAction($ident, $action);
            }
        }

        // Migration v1.3: Register 1516 wirkt bei der WPL-A AC als "Grenze Kühlen",
        // nicht als Raumsolltemperatur -> bestehende Variable umbenennen
        $vid = @$this->GetIDForIdent('KuehlRaumSoll');
        if ($vid !== false && $vid > 0 && IPS_GetName($vid) === 'Kühlen Raum-Soll') {
            IPS_SetName($vid, 'Grenze Kühlen (Außentemperatur)');
        }
    }

    private function RegisterProfiles(): void
    {
        $this->ProfileFloat('SWPL.TempC', ' °C', 1, 0, 0, 0, 'Temperature');
        $this->ProfileFloat('SWPL.TempDiff', ' K', 1, 0, 0, 0, 'Temperature');
        $this->ProfileFloat('SWPL.Feuchte', ' %', 1, 0, 100, 0, 'Drops');
        $this->ProfileFloat('SWPL.TempHK', ' °C', 1, 5, 30, 0.5, 'Temperature');
        $this->ProfileFloat('SWPL.TempWW', ' °C', 1, 10, 60, 0.5, 'Temperature');
        $this->ProfileFloat('SWPL.TempKuehlVL', ' °C', 1, 15, 25, 0.5, 'Snowflake');
        $this->ProfileFloat('SWPL.TempGrenzeKuehl', ' °C', 1, 15, 40, 0.5, 'Snowflake');
        $this->ProfileFloat('SWPL.Hysterese', ' K', 1, 1, 5, 0.5, 'Temperature');
        $this->ProfileFloat('SWPL.Heizkurve', '', 2, 0, 3, 0.05, 'Graph');
        $this->ProfileFloat('SWPL.Druck', ' bar', 2, 0, 0, 0, 'Gauge');
        $this->ProfileFloat('SWPL.Durchfluss', ' l/min', 1, 0, 0, 0, 'Distance');
        $this->ProfileFloat('SWPL.kWh', ' kWh', 1, 0, 0, 0, 'Electricity');
        $this->ProfileFloat('SWPL.MWh', ' MWh', 3, 0, 0, 0, 'Electricity');
        $this->ProfileFloat('SWPL.COP', '', 2, 0, 0, 0, 'Graph');

        if (!IPS_VariableProfileExists('SWPL.Stunden')) {
            IPS_CreateVariableProfile('SWPL.Stunden', 1);
            IPS_SetVariableProfileText('SWPL.Stunden', '', ' h');
            IPS_SetVariableProfileIcon('SWPL.Stunden', 'Clock');
        }

        if (!IPS_VariableProfileExists('SWPL.Aktiv')) {
            IPS_CreateVariableProfile('SWPL.Aktiv', 0);
            IPS_SetVariableProfileIcon('SWPL.Aktiv', 'Power');
            IPS_SetVariableProfileAssociation('SWPL.Aktiv', 0, 'Aus', '', -1);
            IPS_SetVariableProfileAssociation('SWPL.Aktiv', 1, 'An', '', 0x00A65E);
        }

        if (!IPS_VariableProfileExists('SWPL.Betriebsart')) {
            IPS_CreateVariableProfile('SWPL.Betriebsart', 1);
            IPS_SetVariableProfileIcon('SWPL.Betriebsart', 'Gear');
            IPS_SetVariableProfileAssociation('SWPL.Betriebsart', 0, 'Notbetrieb', '', 0xFF4040);
            IPS_SetVariableProfileAssociation('SWPL.Betriebsart', 1, 'Bereitschaft', '', 0x808080);
            IPS_SetVariableProfileAssociation('SWPL.Betriebsart', 2, 'Programmbetrieb', '', 0x00A65E);
            IPS_SetVariableProfileAssociation('SWPL.Betriebsart', 3, 'Komfortbetrieb', '', 0xE8A33D);
            IPS_SetVariableProfileAssociation('SWPL.Betriebsart', 4, 'ECO-Betrieb', '', 0x3D9EE8);
            IPS_SetVariableProfileAssociation('SWPL.Betriebsart', 5, 'Warmwasserbetrieb', '', 0xE86A3D);
        }

        if (!IPS_VariableProfileExists('SWPL.SGZustand')) {
            IPS_CreateVariableProfile('SWPL.SGZustand', 1);
            IPS_SetVariableProfileIcon('SWPL.SGZustand', 'EnergySolar');
            IPS_SetVariableProfileAssociation('SWPL.SGZustand', 1, '1 – Sperre (Frostschutz)', '', 0xFF4040);
            IPS_SetVariableProfileAssociation('SWPL.SGZustand', 2, '2 – Normalbetrieb', '', 0x00A65E);
            IPS_SetVariableProfileAssociation('SWPL.SGZustand', 3, '3 – Forcierter Betrieb', '', 0xE8A33D);
            IPS_SetVariableProfileAssociation('SWPL.SGZustand', 4, '4 – Maximalwerte', '', 0xE86A3D);
        }
    }

    private function ProfileFloat(string $name, string $suffix, int $digits, float $min, float $max, float $step, string $icon): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, 2);
        }
        IPS_SetVariableProfileText($name, '', $suffix);
        IPS_SetVariableProfileDigits($name, $digits);
        IPS_SetVariableProfileValues($name, $min, $max, $step);
        IPS_SetVariableProfileIcon($name, $icon);
    }

    // =====================================================================
    // Archivierung
    // =====================================================================

    /**
     * Aktiviert die Archivierung (Archive Control) für alle Zahlen-/Bool-Variablen.
     */
    private function SetupArchive(): void
    {
        if (!$this->ReadPropertyBoolean('EnableArchive')) {
            return;
        }
        $acIDs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($acIDs) === 0) {
            return;
        }
        $ac = $acIDs[0];
        $skip = ['StatusText' => true, 'Regler' => true, 'LastUpdate' => true];

        $changed = false;
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $cid) {
            $obj = IPS_GetObject($cid);
            if ($obj['ObjectType'] !== 2 /* Variable */) {
                continue;
            }
            if (isset($skip[$obj['ObjectIdent']])) {
                continue;
            }
            if (!AC_GetLoggingStatus($ac, $cid)) {
                AC_SetLoggingStatus($ac, $cid, true);
                $changed = true;
            }
        }
        if ($changed) {
            IPS_ApplyChanges($ac);
        }
    }

    // =====================================================================
    // Diagnose
    // =====================================================================

    /**
     * Gibt alle relevanten Register als Rohwerte aus (für die Fehlersuche).
     */
    public function DumpRegisters(): string
    {
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            return 'Bitte zuerst die IP-Adresse konfigurieren.';
        }
        $sock = $this->mbConnect();
        if ($sock === false) {
            return 'Keine Verbindung zum ISG.';
        }

        $out = '';
        try {
            $off = $this->detectOffset($sock);
            $out .= "Adress-Offset: {$off}\n";

            $blocks = [
                ['Block 1 Systemwerte (Input)', 4, 501, 48],
                ['Block 1b Raumwerte HK/KK (Input)', 4, 584, 25],
                ['Block 2 Parameter (Holding)', 3, 1501, 21],
                ['Block 3 Status (Input)', 4, 2501, 11],
                ['Block 6 SG/Regler (Input)', 4, 5001, 2]
            ];
            foreach ($blocks as [$name, $fc, $start, $qty]) {
                $out .= "\n== {$name} ==\n";
                $r = $this->mbRead($sock, $fc, $start + $off, $qty);
                if ($r === null) {
                    $out .= "keine Antwort\n";
                    continue;
                }
                foreach ($r as $i => $raw) {
                    $reg = $start + $i;
                    if ($raw === self::NA_RAW) {
                        $out .= sprintf("%d: n/v\n", $reg);
                    } else {
                        $signed = ($raw > 0x7FFF) ? $raw - 0x10000 : $raw;
                        $out .= sprintf("%d: %u  [/10=%.1f  /100=%.2f]\n", $reg, $raw, $signed / 10, $signed / 100);
                    }
                }
            }
        } finally {
            fclose($sock);
        }
        return $out;
    }

    // =====================================================================
    // WebHook-Registrierung
    // =====================================================================

    private function RegisterHook(string $hook): void
    {
        $ids = IPS_GetInstanceListByModuleID(self::GUID_WEBHOOK_CONTROL);
        if (count($ids) === 0) {
            return;
        }
        $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true);
        if (!is_array($hooks)) {
            $hooks = [];
        }
        foreach ($hooks as $index => $entry) {
            if ($entry['Hook'] === $hook) {
                if ($entry['TargetID'] === $this->InstanceID) {
                    return;
                }
                $hooks[$index]['TargetID'] = $this->InstanceID;
                IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
                IPS_ApplyChanges($ids[0]);
                return;
            }
        }
        $hooks[] = ['Hook' => $hook, 'TargetID' => $this->InstanceID];
        IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($ids[0]);
    }
}
