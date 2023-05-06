<?php
require_once 'modules/admin/models/RegistrarPlugin.php';

class PluginSynergywholesale extends RegistrarPlugin
{
    public $features = [
        'nameSuggest' => true,
        'importDomains' => true,
        'importPrices' => true, // This option allows TLD Importer to work for this registra
    ];

    private $dnsTypes = ['A', 'AAAA',  'MX', 'CNAME', 'TXT'];

    function getVariables()
    {
        $variables = array(
            lang('Plugin Name') => array (
                                'type'          =>'hidden',
                                'description'   =>lang('How CE sees this plugin (not to be confused with the Signup Name)'),
                                'value'         =>lang('Synergy Wholesale')
                               ),
            lang('Reseller ID') => array(
                                'type'          =>'text',
                                'description'   =>lang('Enter your Reseller ID.'),
                                'value'         =>''
                               ),
            lang('API Key')  => array(
                                'type'          =>'password',
                                'description'   =>lang('Enter your API Key.'),
                                'value'         =>'',
                                ),
            lang('Supported Features')  => array(
                                'type'          => 'label',
                                'description'   => '* '.lang('TLD Lookup').'<br>* '.lang('Domain Registration').' <br>* '.lang('Existing Domain Importing').' <br>* '.lang('Get / Set Auto Renew Status').' <br>* '.lang('Get / Set DNS Records').' <br>* '.lang('Get / Set Nameserver Records').' <br>* '.lang('Get / Set Contact Information').' <br>* '.lang('Get / Set Registrar Lock').' <br>* '.lang('Initiate Domain Transfer').' <br>* '.lang('Automatically Renew Domain').' <br>* '.lang('Retrieve EPP Code'),
                                'value'         => ''
                                ),
            lang('Actions') => array (
                                'type'          => 'hidden',
                                'description'   => lang('Current actions that are active for this plugin (when a domain isn\'t registered)'),
                                'value'         => 'Register'
                                ),
            lang('Registered Actions') => array (
                                'type'          => 'hidden',
                                'description'   => lang('Current actions that are active for this plugin (when a domain is registered)'),
                                'value'         => 'Renew (Renew Domain),DomainTransferWithPopup (Initiate Transfer),IDProtect (Toggle ID Protection),Cancel',
                                ),
            lang('Registered Actions For Customer') => array (
                                'type'          => 'hidden',
                                'description'   => lang('Current actions that are active for this plugin (when a domain is registered)'),
                                'value'         => 'IDProtect (Toggle ID Protection)',
            )
        );

        return $variables;
    }

    function checkDomain($params)
    {
        $tld = $params['tld'];
        $sld = $params['sld'];

        $response = $this->makeRequest('checkDomain', ['domainName' => $sld . '.' . $tld]);
        if ($response->status == 'AVAILABLE') {
            $status = 0;
        } elseif ($response->status == 'UNAVAILABLE') {
            $status = 1;
        } elseif ($response->status == 'ERR_DOMAINCHECK_FAILED') {
            CE_Lib::log(4, $response->errorMessage);
            $status = 5;
        }
        


        $domains[] = [
            'tld' => $tld,
            'domain' => $sld,
            'status' => $status
        ];

        return ['result' => $domains];
        
        

            
    }

    /**
     * Initiate a domain transfer
     *
     * @param array $params
     */
    function doDomainTransferWithPopup($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $transferid = $this->initiateTransfer($this->buildTransferParams($userPackage, $params));
        $userPackage->setCustomField("Registrar Order Id", $userPackage->getCustomField("Registrar") . '-' . $params['userPackageId']);
        return "Transfer has been initiated.";
    }

    /**
     * Register domain name
     *
     * @param array $params
     */
    function doRegister($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $orderid = $this->registerDomain($this->buildRegisterParams($userPackage, $params));
        $userPackage->setCustomField("Registrar Order Id", $userPackage->getCustomField("Registrar").'-'.$orderid);
        return $userPackage->getCustomField('Domain Name') . ' has been registered.';
    }
    
    function doUpdate($args) {}
    
    function doIDProtect($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $response = $this->makeRequest('domainInfo', ['domainName' => $userPackage->getCustomField('Domain Name')]);
                if ($response->status != 'OK') {
            throw new CE_Exception($response->errorMessage);
        } 
        

             
        if ($response->idProtect == 'Enabled') {
          //  $idProtectCheck->setCustomField('idProtect', 'Disabled');
                //  $userPackage->setCustomField('ID Protection', 'Disabled');
            $response = $this->makeRequest('disableIDProtection', ['domainName' => $userPackage->getCustomField('Domain Name')]);
                if ($response->status != 'OK') {
            throw new CE_Exception($response->errorMessage);
        }
               $userPackage->setCustomField('ID Protection', 'Disabled', CUSTOM_FIELDS_FOR_PACKAGE);
        return $this->user->lang('ID Protection - Disabled');
        } else {
           // $idProtectCheck->setCustomField('idProtect', 'Enabled');
          //  $userPackage->setCustomField('ID Protection', 'Enabled');

            $response = $this->makeRequest('enableIDProtection', ['domainName' => $userPackage->getCustomField('Domain Name')]);
                if ($response->status != 'OK') {
            throw new CE_Exception($response->errorMessage);
        }
                $userPackage->setCustomField('ID Protection', 'Enabled', CUSTOM_FIELDS_FOR_PACKAGE);
        return $this->user->lang('ID Protection - Enabled');
        }

        
        
    }
    


    /**
     * Renew domain name
     *
     * @param array $params
     */
    function doRenew($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $orderid = $this->renewDomain($this->buildRenewParams($userPackage, $params));
        $userPackage->setCustomField("Registrar Order Id", $userPackage->getCustomField("Registrar") . '-' . $params['userPackageId']);
        return $userPackage->getCustomField('Domain Name') . ' has been renewed.';
    }

    function getTransferStatus($params)
    {
        $response = $this->makeRequest('domainInfo', ['domainName' => $params['sld'] . '.' . $params['tld']]);

        if (in_array(strtolower($response->domain_status), ['ok', 'clienttransferprohibited'])) {
            $userPackage = new UserPackage($params['userPackageId']);
            $userPackage->setCustomField('Transfer Status', 'Completed');
            return 'Completed';
        }
        return $response->domain_status;
    }

    function initiateTransfer($params)
    {
        if ($params['tld'] == 'uk') {
            throw new CE_Exception('.uk transfers must be handled manually and assigned to the tag "SYNERGY-AU"');
        }

        $arguments = [
            'domainName' => $params['sld'] . '.' . $params['tld'],
            'authInfo' => $params['eppCode'],
            'firstname' => $params['RegistrantFirstName'],
            'lastname' => $params['RegistrantLastName'],
            'address' => $params['RegistrantAddress1'],
            'suburb' => $params['RegistrantCity'],
            'state' => $this->validateState($params['RegistrantStateProvince'], $params['RegistrantCountry']),
            'country' => $params['RegistrantCountry'],
            'postcode' => $params['RegistrantPostalCode'],
            'phone' => $this->validatePhone($params['RegistrantPhone'], $params['RegistrantCountry']),
            'email' => $params['RegistrantEmailAddress'],
        ];

        $response = $this->makeRequest('transferDomain', $arguments);
        return '';
    }

    function renewDomain($params)
    {
        $response = $this->makeRequest('renewDomain', [
            'domainName' => $params['sld'] . '.' . $params['tld'],
            'years' => $params['NumYears']
        ]);

        if ($response->status != 'OK') {
            throw new CE_Exception($response->errorMessage);
        }
    }



    function getTLDsAndPrices($params)
    {

		$response = $this->makeRequest('getDomainPricing');
        if ($response->status != 'OK') {
            throw new CE_Exception($response->errorMessage);
        }

        $data = $response;
        $tlds = [];
       foreach ($data->pricing as $value) {
            $tld = $value->tld;
            $tlds[$tld]['pricing']['register'] = $value->register_1_year;
            $tlds[$tld]['pricing']['transfer'] = $value->transfer;
            $tlds[$tld]['pricing']['renew'] = $value->renew;
        }
        return $tlds;
    }




    function registerDomain($params)
    {
        $arguments = [
            'domainName' => $params['sld'] . '.' . $params['tld'],
            'years' => $params['NumYears'],
            'registrant_organisation' => $params['RegistrantOrganizationName'],
            'registrant_firstname' => $params['RegistrantFirstName'],
            'registrant_lastname' => $params['RegistrantLastName'],
            'registrant_email' => $params['RegistrantEmailAddress'],
            'registrant_phone' => $this->validatePhone($params['RegistrantPhone'], $params['RegistrantCountry']),
            'registrant_address' => [$params['RegistrantAddress1']],
            'registrant_suburb' => $params['RegistrantCity'],
            'registrant_state' => $this->validateState($params['RegistrantStateProvince'], $params['RegistrantCountry']),
            'registrant_postcode' => $params['RegistrantPostalCode'],
            'registrant_country' => $params['RegistrantCountry'],
            'technical_organisation' => $params['RegistrantOrganizationName'],
            'technical_firstname' => $params['RegistrantFirstName'],
            'technical_lastname' => $params['RegistrantLastName'],
            'technical_email' => $params['RegistrantEmailAddress'],
            'technical_phone' => $this->validatePhone($params['RegistrantPhone'], $params['RegistrantCountry']),
            'technical_address' => [$params['RegistrantAddress1']],
            'technical_suburb' => $params['RegistrantCity'],
            'technical_state' => $this->validateState($params['RegistrantStateProvince'], $params['RegistrantCountry']),
            'technical_postcode' => $params['RegistrantPostalCode'],
            'technical_country' => $params['RegistrantCountry'],
            'admin_organisation' => $params['RegistrantOrganizationName'],
            'admin_firstname' => $params['RegistrantFirstName'],
            'admin_lastname' => $params['RegistrantLastName'],
            'admin_email' => $params['RegistrantEmailAddress'],
            'admin_phone' => $this->validatePhone($params['RegistrantPhone'], $params['RegistrantCountry']),
            'admin_address' => [$params['RegistrantAddress1']],
            'admin_suburb' => $params['RegistrantCity'],
            'admin_state' => $this->validateState($params['RegistrantStateProvince'], $params['RegistrantCountry']),
            'admin_postcode' => $params['RegistrantPostalCode'],
            'admin_country' => $params['RegistrantCountry'],
            'billing_organisation' => $params['RegistrantOrganizationName'],
            'billing_firstname' => $params['RegistrantFirstName'],
            'billing_lastname' => $params['RegistrantLastName'],
            'billing_email' => $params['RegistrantEmailAddress'],
            'billing_phone' => $this->validatePhone($params['RegistrantPhone'], $params['RegistrantCountry']),
            'billing_address' => [$params['RegistrantAddress1']],
            'billing_suburb' => $params['RegistrantCity'],
            'billing_state' => $this->validateState($params['RegistrantStateProvince'], $params['RegistrantCountry']),
            'billing_postcode' => $params['RegistrantPostalCode'],
            'billing_country' => $params['RegistrantCountry']
        ];

        if (isset($params['NS1'])) {
            for ($i = 1; $i <= 12; $i++) {
                if (isset($params["NS$i"])) {
                    $arguments['nameServers'][] = $params["NS$i"]['hostname'];
                } else {
                    break;
                }
            }
        }

        $command = 'domainRegister';
        if (end(explode('.', $params['tld'])) == 'au') {
            $command = 'domainRegisterAU';
            $arguments['registrantName'] = $params['RegistrantFirstName'] . ' ' . $params['RegistrantLastName'];
            $arguments['registrantID'] = $params['ExtendedAttributes']['au_registrantid'];
            $arguments['registrantIDType'] = $params['ExtendedAttributes']['au_entityidtype'];
        } elseif ($params['tld'] == 'us') {
            $command = 'domainRegisterUS';
            $arguments['appPurpose'] = $params['ExtendedAttributes']['us_purpose'];
            $arguments['nexusCategory'] = $params['ExtendedAttributes']['us_nexus'];
        }

        $response = $this->makeRequest($command, $arguments);
        if ($response->status != 'OK') {
            throw new CE_Exception($response->errorMessage);
        }
    }

    function getContactInformation($params)
    {
        $response = $this->makeRequest('listContacts', ['domainName' => $params['sld'] . '.' . $params['tld']]);

        $info = [];
        foreach (array('registrant', 'billing', 'admin', 'tech') as $type) {
            switch ($type) {
                case 'registrant':
                    $internalType = 'Registrant';
                    break;

                case 'billing':
                    $internalType = 'AuxBilling';
                    break;

                case 'admin':
                    $internalType = 'Admin';
                    break;

                case 'tech':
                    $internalType = 'Tech';
                    break;
            }

            if (isset($response->$type)) {
                $info[$internalType]['Company'] = array($this->user->lang('Organization'), isset($response->$type->organisation) ? $response->$type->organisation : '');
                $info[$internalType]['First Name'] = array($this->user->lang('First Name'), $response->$type->firstname);
                $info[$internalType]['Last Name']  = array($this->user->lang('Last Name'), $response->$type->lastname);
                $info[$internalType]['Address 1']  = array($this->user->lang('Address').' 1', $response->$type->address1);
                $info[$internalType]['Address 2']  = array($this->user->lang('Address').' 2', isset($response->$type->address2) ? $response->$type->address2 : '');
                $info[$internalType]['City']      = array($this->user->lang('City'), $response->$type->suburb);
                $info[$internalType]['State / Province']  = array($this->user->lang('Province').'/'.$this->user->lang('State'), $response->$type->state);
                $info[$internalType]['Country']   = array($this->user->lang('Country'), $response->$type->country);
                $info[$internalType]['Postal Code']  = array($this->user->lang('Postal Code'), $response->$type->postcode);
                $info[$internalType]['Email Address']     = array($this->user->lang('E-mail'), $response->$type->email);
                $info[$internalType]['Phone']  = array($this->user->lang('Phone'), $response->$type->phone);
                $info[$internalType]['Fax']       = array($this->user->lang('Fax'), isset($response->$type->fax) ? $response->$type->fax : '');
            } else {
                $info[$internalType] = array(
                    'Company'  => array($this->user->lang('Organization'), ''),
                    'First Name'         => array($this->user->lang('First Name'), ''),
                    'Last Name'          => array($this->user->lang('Last Name'), ''),
                    'Address 1'          => array($this->user->lang('Address').' 1', ''),
                    'Address 2'          => array($this->user->lang('Address').' 2', ''),
                    'City'              => array($this->user->lang('City'), ''),
                    'State / Province'         => array($this->user->lang('Province').'/'.$this->user->lang('State'), ''),
                    'Country'           => array($this->user->lang('Country'), ''),
                    'Postal Code'        => array($this->user->lang('Postal Code'), ''),
                    'Email Address'      => array($this->user->lang('E-mail'), ''),
                    'Phone'             => array($this->user->lang('Phone'), ''),
                    'Fax'               => array($this->user->lang('Fax'), ''),
                );
            }
        }
        return $info;
    }

    function setContactInformation($params)
    {
        $arguments['domainName'] = $params['sld'] . '.' . $params['tld'];
        $arguments['registrant_firstname'] = $params['Registrant_First_Name'];
        $arguments['registrant_lastname'] = $params['Registrant_Last_Name'];
        $arguments['registrant_address'] = [$params['Registrant_Address_1'], $params['Registrant_Address_2']];
        $arguments['registrant_email'] = $params['Registrant_Email_Address'];
        $arguments['registrant_suburb'] = $params['Registrant_City'];
        $arguments['registrant_state'] = $params['Registrant_State_/_Province'];
        $arguments['registrant_country'] = $params['Registrant_Country'];
        $arguments['registrant_postcode'] = $params['Registrant_Postal_Code'];
        $arguments['registrant_phone'] = $this->validatePhone($params['Registrant_Phone'], $params['Registrant_Country']);
        $arguments['registrant_fax']   = $this->validatePhone($params['Registrant_Fax'], $params['Registrant_Country']);

        $response = $this->makeRequest('updateContact', $arguments);
        if ($response->status != 'OK') {
            throw new CE_Exception($response->errorMessage);
        }
        return $this->user->lang('Contact Information updated successfully.');
    }

    function getNameServers($params)
    {
        $response = $this->makeRequest('domainInfo', ['domainName' => $params['sld'] . '.' . $params['tld']]);
        if ($response->status != 'OK') {
            throw new CE_Exception($response->errorMessage);
        }
        $data = [];
        foreach ($response->nameServers as $nameserver) {
            $data[] = $nameserver;
        }

        $data['usesDefault'] = false;
        if ($response->dnsConfig == 4) {
            $data['usesDefault'] = true;
        }
        $data['hasDefault'] = true;

        return $data;
    }

    function setNameServers($params)
    {
        $arguments = [];
        $arguments['domainName'] = $params['sld'] . '.' . $params['tld'];
        if ($params['default'] == true) {
            $arguments['dnsConfigType'] = 4;
        } else {
            $arguments['dnsConfigType'] = 1;
            foreach ($params['ns'] as $key => $value) {
                $arguments['nameServers'][] = $value;
            }
        }

        $response = $this->makeRequest('updateNameServers', $arguments);
    }

    function getGeneralInfo($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $data = [];
        $response = $this->makeRequest('domainInfo', ['domainName' => $params['sld'] . '.' . $params['tld']]);

        $data['id'] = $response->domainRoid;
        $data['domain'] = $response->domainName;
        $data['expiration'] = date('m/d/Y', strtotime($response->domain_expiry));
        $data['registrationstatus'] = $response->status;
        $data['purchasestatus'] = $response->status;
        $data['id_protect'] = ($response->idProtect == 'Disabled') ? false : true;
        
        if($response->idProtect == 'Enabled') {
               $userPackage->setCustomField('ID Protection', 'Enabled', CUSTOM_FIELDS_FOR_PACKAGE);            
        } elseif ($response->idProtect == 'Disabled') {
               $userPackage->setCustomField('ID Protection', 'Disabled', CUSTOM_FIELDS_FOR_PACKAGE);
        } else {
                $userPackage->setCustomField('ID Protection', 'Not Eligible', CUSTOM_FIELDS_FOR_PACKAGE);
        }
        
        
        $data['autorenew'] = ($response->autoRenew == 'off') ? false : true;
        $data['is_registered'] = false;
        $data['is_expired'] = false;
        if (in_array(strtolower($response->domain_status), ['ok', 'clienttransferprohibited'])) {
            $data['is_registered'] = true;
        } elseif (in_array(strtolower($response->domain_status), ['expired', 'clienthold'])) {
            $data['is_expired'] = true;
        } elseif (in_array(strtolower($response->domain_status), ['deleted', 'dropped', 'policydelete'])) {
            $data['registrationstatus'] = 'RGP';
        }
        return $data;
    }

    function fetchDomains($params)
    {
        $domains = [];
        $response = $this->makeRequest('listDomains');

        if ($response->status == 'OK') {
            foreach ($response->domainList as $domain) {
                list($sld, $tld) = DomainNameGateway::splitDomain($domain->domainName);

                $data['id'] = $domain->domainRoid;
                $data['sld'] = $sld;
                $data['tld'] = $tld;
                $data['exp'] = $domain->domain_expiry;
                $domains[] = $data;
            }
        }
        $metaData = [];
        return array($domains, $metaData);
    }

    function setAutorenew($params)
    {
        $command = 'disableAutoRenewal';
        if ($params['autorenew'] == 1) {
            $command = 'enableAutoRenewal';
        }

        $response = $this->makeRequest($command, ['domainName' => $params['sld'] . '.' . $params['tld']]);
        return "Domain updated successfully";
    }

    function getRegistrarLock($params)
    {
        $response = $this->makeRequest('domainInfo', ['domainName' => $params['sld'] . '.' . $params['tld']]);
        if ($response->domain_status == 'clientTransferProhibited') {
            return true;
        } else {
            return false;
        }
    }

    function doSetRegistrarLock($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $this->setRegistrarLock($this->buildLockParams($userPackage, $params));
        return "Updated Registrar Lock.";
    }

    function setRegistrarLock($params)
    {
        $response = $this->makeRequest('domainInfo', ['domainName' => $params['sld'] . '.' . $params['tld']]);
        if ($response->domain_status == 'clientTransferProhibited') {
            $response = $this->makeRequest('unlockDomain', ['domainName' => $params['sld'] . '.' . $params['tld']]);
        } else {
            $response = $this->makeRequest('lockDomain', ['domainName' => $params['sld'] . '.' . $params['tld']]);
        }
        
            if ($params['package_addons']['SYNERGYIDPROTECT'] == '1') {
                $response = $this->_makeRequest('enableIDProtection', ['domainName' => $params['sld'] . '.' . $params['tld']]);
            }
            else {
            $response = $this->_makeRequest('disableIDProtection', ['domainName' => $params['sld'] . '.' . $params['tld']]);
            throw new CE_Exception($params['package_addons']['IDPROTECT']);
            }
    }

    function getDNS($params)
    {
        $response = $this->makeRequest('listDNSZone', ['domainName' => $params['sld'] . '.' . $params['tld']]);
        if ($response->status == 'ERR_LISTDNSZONE_FAILED') {
            throw new CE_Exception($response->errorMessage);
        }
        $records = [];
        foreach ($response->records as $row) {
            if (in_array($row->type, ['NS', 'SOA'])) {
                continue;
            }
            $record = [
                'id' =>  $row->id,
                'hostname' => $row->hostName,
                'address' =>  $row->content,
                'type' => $row->type
            ];
            $records[] = $record;
        }

        return [
            'records' => $records,
            'types' => $this->dnsTypes,
            'default' => true
        ];
    }

    function setDNS($params)
    {
        // No edit, so have to delete and re-add
        $response = $this->makeRequest('listDNSZone', ['domainName' => $params['sld'] . '.' . $params['tld']]);
        if ($response->status == 'ERR_LISTDNSZONE_FAILED') {
            throw new CE_Exception($response->errorMessage);
        }
        foreach ($response->records as $row) {
            if (in_array($row->type, $this->dnsTypes)) {
                $this->makeRequest('deleteDNSRecord', [
                    'domainName' => $params['sld'] . '.' . $params['tld'],
                    'recordID' => $row->id
                ]);
            }
        }

        foreach ($params['records'] as $record) {
            $arguments = [
                'domainName' => $params['sld'] . '.' . $params['tld'],
                'recordName' => $record['hostname'],
                'recordType' => $record['type'],
                'recordContent' => $record['address'],
                'recordTTL' => 86400,
            ];
            if ($record['type'] == 'MX') {
                $arguments['recordPrio'] = '0';
            }
            $this->makeRequest('addDNSRecord', $arguments);
        }
    }

    function getEPPCode($params)
    {
        $response = $this->makeRequest('domainInfo', ['domainName' => $params['sld'] . '.' . $params['tld']]);
        if (!empty($response->domainPassword)) {
            return $response->domainPassword;
        }
        return '';
    }

    function sendTransferKey($params)
    {
    }
    
    function getIDProtectStatus($params)
    {
        $response = $this->makeRequest('domainInfo', ['domainName' => $params['sld'] . '.' . $params['tld']]);
         $idProtectCheck = $userPackage->getCustomField('idProtect');
        if ($response->idProtect == 'Enabled') {
            $idProtectCheck->setCustomField('idProtect', 'Enabled');
            return true;
            throw new CE_Exception('Alert1');
        } else {
            $idProtectCheck->setCustomField('idProtect', 'Disabled');
            return false;
           throw new CE_Exception('Alert2');
        }
    }

       
 //        $idProtectCheck = $userPackage->getCustomField('idProtect');
 //       if ($response->idProtect == 'Enabled') {
 //           $idProtectCheck->setCustomField('idProtect', 'Yes');
 //           return true;
 //       } else {
 //           $idProtectCheck->setCustomField('idProtect', 'No');
 //           return false;
 //       }



    private function validateState($state, $country)
    {
        if ($country != 'AU') {
            return $state;
        }

        $state = trim($state);
        $state = preg_replace('/ /', '', $state);
        $state = preg_replace('/\./', '', $state);

        $state = strtoupper($state);

        switch ($state) {
            case "VICTORIA":
            case "VIC":
                return "VIC";

            case "NEWSOUTHWALES":
            case "NSW":
                return "NSW";

            case "QUEENSLAND":
            case "QLD":
                return "QLD";

            case "AUSTRALIANCAPITALTERRITORY":
            case "AUSTRALIACAPITALTERRITORY":
            case "ACT":
                return "ACT";

            case "SOUTHAUSTRALIA":
            case "SA":
                return "SA";

            case "WESTERNAUSTRALIA":
            case "WA":
                return "WA";

            case "NORTHERNTERRITORY":
            case "NT":
                return "NT";

            case "TASMANIA":
            case "TAS":
                return "TAS";

            default:
                return $state;
        }
    }

    private function validatePhone($phone, $country)
    {
        // strip all non numerical values
        $phone = preg_replace('/[^\d]/', '', $phone);

        if ($phone == '') {
            return $phone;
        }

        $query = "SELECT phone_code FROM country WHERE iso=? AND phone_code != ''";
        $result = $this->db->query($query, $country);
        if (!$row = $result->fetch()) {
            return $phone;
        }

        // check if code is already there
        $code = $row['phone_code'];
        $phone = preg_replace("/^($code)(\\d+)/", '+\1.\2', $phone);
        if ($phone[0] == '+') {
            return $phone;
        }

        // if not, prepend it
        return "+$code.$phone";
    }

    private function makeRequest($command, $params = [], $skiperrorchecking = false)
    {
        $request = [];
        $request['resellerID'] = $this->settings->get('plugin_synergywholesale_Reseller ID');
        $request['apiKey'] = $this->settings->get('plugin_synergywholesale_API Key');
        $request = array_merge($request, $params);

        try {
            $client = new SoapClient(null, ['location' => 'https://api.synergywholesale.com', 'uri' => ""]);
            CE_Lib::log(4, "Calling $command at synergywholesale: ");
            CE_Lib::log(4, $request);
            $result = $client->{$command}($request);
            CE_Lib::log(4, $result);

            return $result;
        } catch (SoapFault $e) {
            throw new CE_Exception("SynergyWholesale Plugin Error: ". $e->getMessage(), EXCEPTION_CODE_CONNECTION_ISSUE);
        }
    }
}
