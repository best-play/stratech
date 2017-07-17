<?php

/**
 * Class for handling Stratech XML requests and responses.
 *
 * @author Rick Niesen <rick@libelnet.nl>
 */
class StratechXML
{
    private $wsdlUrl;

    public function __construct($wsdlUrl)
    {
        $this->wsdlUrl = $wsdlUrl;
    }

    private function handleSOAPRequest($XMLRequest, $version = "1")
    {
        $client = new SoapClient($this->wsdlUrl);

        try {
            if ($version == "1") {
                $this->sLastXmlResponse = $client->HandleRequest_UI($XMLRequest);
            } else {
                $this->sLastXmlResponse = $client->HandleRequest($XMLRequest);
            }

            $simplexml = new SimpleXMLElement($this->sLastXmlResponse);

            if ((string)$simplexml->CODE == "NO CONNECTION") {
                die('STRATECH_ERROR_CONNECTION_FAILED');
            }

        } catch (SoapFault $e) {

            die('STRATECH_ERROR_CONNECTION_FAILED');

        }

        return $simplexml;
    }

    private function makeXMLRequest($documentNumber, $xmlRequestData, $version = '1')
    {
        $XML_request = '
          <DOCUMENT>
            <NUMBER>' . $documentNumber . '</NUMBER>
            <VERSION>' . $version . '</VERSION>
            <HEADER>
              <REQUEST>
                <OMSCHRIJVING></OMSCHRIJVING>
                <DATUM>' . date('m-d-Y') . '</DATUM>
                <TIJD>' . date('G:i') . '</TIJD>
              </REQUEST>
            </HEADER>
            ' . $xmlRequestData . '
            <CONTROL>
              <STATUS>
                <CODE/>
                <MESSAGE/>
              </STATUS>
            </CONTROL>
          </DOCUMENT>
          ';

        $simplexml = $this->handleSOAPRequest($XML_request);

        if ($simplexml->DATA->RESULT == "UNKNOWN") {
            return false;
        }

        $result = $simplexml->DATA;
        return $result;
    }

    /**
     * Document 1: Versie informatie
     */
    public function getVersion()
    {
        $xmlRequestData = '<DATA/>';

        if (!$result = $this->makeXMLRequest(1, $xmlRequestData)) {
            return false;
        }

        return $result;
    }

    /**
     * Document 1023: Get Booking
     */
    public function getBooking()
    {
        $xmlRequestData = '
            <DATA>
                <AANKOMST>'. date('m-d-Y').'</AANKOMST>
                <VERTREK>'. date('m-d-Y').'</VERTREK>
                <SELECTIE>
                    <BOEKDATUM/>
                    <DISTRIBUTIEKANAAL/>
                    <RESERVERINGSCATEGORIE/>
                    <PARK/>
                    <OBJECTSOORT/>
                    <OBJECTTYPE/>
                    <VOORKEURSBOEKING/>
                    <STATUS/>
                    <LAND/>
                    <TAAL/>
                    <VERLOOPDAGEN/>
                    <TOONPERSONEN>Y</TOONPERSONEN>
                    <TOONARTIKELEN>Y</TOONARTIKELEN>
                    <TOONMEDEGASTEN>T</TOONMEDEGASTEN>
                </SELECTIE>
            </DATA>
        ';

        if (!$result = $this->makeXMLRequest(1023, $xmlRequestData)) {
            die('STRATECH_ERROR_FAILED');
        }

        return $result->RESULT->GASTEN->GAST;
    }
}

class BookingsRepo
{
    protected $_config, $_mysqli;

    public function __construct()
    {
        /** Loading config, connect to database */
        $this->_config = [
            'host'      => 'localhost',
            'dbname'    => 'stratech',          // Database name
            'username'  => 'stratech_user',     // Database username
            'password'  => 'stratech_pass',     // Database password
        ];
        $this->_mysqli = new mysqli($this->_config['host'], $this->_config['username'], $this->_config['password'], $this->_config['dbname']);
    }

    public function checkFileExist($file_path) {
        if (file_exists($file_path))
            return true;
        return false;
    }

    /**
     * Save
     * @param SimpleXMLElement $xml
     * @return bool
     */
    public function saveAction($file_path, $xml)
    {
        $xml->asXML($file_path);

        /* If file is not saved */
        if (!$this->checkFileExist($file_path))
            return false;

        try {
            $reservering_id = $xml->booking->name->bookingnr;
            $date = date("Y-m-d H:i:s");
            $mysqli = $this->_mysqli->query("INSERT INTO newbookingtrack (reservering_id, created) VALUES ('$reservering_id', '$date')");
            if (!$mysqli)
                die('STRATECH_ERROR_CONNECTION_FAILED');

            return true;
        } catch (Exception $e) {
            die('STRATECH_ERROR_FAILED');
        }

    }

    /* Check if booking already processed */
    public function checkBookingExist($xml) {
        try {
            $reservering_id = $xml->RESERVERINGEN->RESERVERING->NUMMER;
            $mysqli = $this->_mysqli->query("SELECT id FROM newbookingtrack WHERE reservering_id = '$reservering_id'");
            if (!$mysqli)
                die('STRATECH_ERROR_CONNECTION_FAILED');

            if ($mysqli->num_rows > 0)
                return true;

            return false;
        } catch (Exception $e) {
            die('STRATECH_ERROR_FAILED');
        }
    }
}

class ProcessBookings
{
    private $file_path;

    public function __construct()
    {
        $this->file_path = getcwd() . '/newbooking.xml';
    }

    /**
     * Data processing
     *
     * @param SimpleXMLElement $stratech_data
     * @return bool
     */
    public function add($stratech_data)
    {
        if (empty($stratech_data))
            return false;

        $repository = new BookingsRepo();

        $xml = $repository->checkFileExist($this->file_path);
        if ($xml) // if file already exist - exit
            return false;

        foreach ($stratech_data as $new_booking) {
            /* Continue when we heva empty booking or booking already processed */
            if (empty($new_booking)) {
                continue;
            } elseif ($repository->checkBookingExist($new_booking)) {
                continue;
            }

            /* Create new xml and fill the data */
            $xml = new SimpleXMLElement('<xml/>');
            $this->addBookingNode($xml, $new_booking);

            /* then save */
            $repository->saveAction($this->file_path, $xml);

            return true;
        }
        return true;
    }

    /**
     * Add node "booking" into Stratech XML data
     *
     * @param SimpleXMLElement $xml
     * @param SimpleXMLElement $newBooking
     */
    private function addBookingNode(&$xml, $newBooking)
    {
        $booking = $xml->addChild('booking');

        $name = $booking->addChild('name');
        $name->addAttribute('info', 'Fam');
        $name->addChild('familyname', $newBooking->ACHTERNAAM . ', ' . $newBooking->VOORLETTERS);
        $name->addChild('bookingnr', $newBooking->RESERVERINGEN->RESERVERING->NUMMER);
        $name->addChild('address', $newBooking->STRAAT . ', ' . $newBooking->HUISNUMMER);
        $name->addChild('postcode', $newBooking->POSTCODE);
        $name->addChild('city', $newBooking->PLAATS);
        $name->addChild('location', ''); // ??
        $name->addChild('comment', ''); // ??
        $name->addChild('custstate', 1); // ??
        $name->addChild('startdate', $newBooking->RESERVERINGEN->RESERVERING->AANKOMST);
        $name->addChild('enddate', $newBooking->RESERVERINGEN->RESERVERING->VERTREK);
    }
}

$wsdl = 'http://hostingrcs.stratechlive.nl/RCSE_WS_LibelTest_f43758bca4fc4e88a9cc684a6c2e1675/RCS-webserver.dll/wsdl/IsdmRecos';

$StratechXML = new StratechXML($wsdl);
$result = $StratechXML->getBooking();

$data = new ProcessBookings();
$data->add($result);