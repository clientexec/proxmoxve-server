<?php

include 'Proxmoxve.class.php';

class PluginProxmoxve extends ServerPlugin
{
    public $features = [
        'packageName' => false,
        'testConnection' => true,
        'showNameservers' => false,
        'directlink' => false
    ];

    public $api;

    public function setup($args)
    {
        $this->api = new Proxmoxve(
            $args['server']['variables']['ServerHostName'],
            $args['server']['variables']['plugin_proxmoxve_Username'],
            "pam",
            $args['server']['variables']['plugin_proxmoxve_Password']
        );
    }

    public function getVariables()
    {
        $variables = [
            lang("Name") => [
                "type" => "hidden",
                "description" => "Used by CE to show plugin - must match how you call the action function names",
                "value" => "ProxmoxVE"
            ],
            lang("Description") => [
                "type" => "hidden",
                "description" => lang("Description viewable by admin in server settings"),
                "value" => lang("ProxmoxVE control panel integration")
            ],
            lang("Username") => [
                "type" => "text",
                "description" => lang("ProxmoxVE Username"),
                "value" => "root"
            ],
            lang("Password") => [
                "type" => "text",
                "description" => lang("ProxmoxVE Password"),
                "value" => ""
            ],
            lang("Gateway IP") => [
                "type" => "text",
                "description" => lang("ProxmoxVE Gateway IP Address"),
                "value" => ""
            ],
            lang("VM Password Custom Field") => [
                "type" => "text",
                "description" => lang("Enter the name of the package custom field that will hold the root password."),
                "value" => ""
            ],
            lang("VM ID Custom Field") => [
                "type" => "text",
                "description" => lang("Enter the name of the package custom field that will hold the ProxmoxVE VM Id."),
                "value" => ""
            ],
            lang("VM Hostname Custom Field") => [
                "type" => "text",
                "description" => lang("Enter the name of the package custom field that will hold the VM hostname."),
                "value" => ""
            ],
            lang("VM MainIp Custom Field") => [
                "type" => "text",
                "description" => lang("Enter the name of the package custom field that will hold the Main IPv4 Address."),
                "value" => ""
            ],
            lang("VM Operating System Custom Field") => [
                "type" => "text",
                "description" => lang("Enter the name of the package custom field that will hold the VM Operating System."),
                "value" => ""
            ],
            lang("Actions") => [
                "type" => "hidden",
                "description" => lang("Current actions that are active for this plugin per server"),
                "value" => "Create,Delete,Suspend,UnSuspend,Reset,Start,Stop,Rebuild"
            ],
            lang('Registered Actions For Customer') => [
                "type" => "hidden",
                "description" => lang("Current actions that are active for this plugin per server for customers"),
                "value" => "Reset,Start,Stop,Rebuild"
            ],
            lang("reseller") => [
                "type" => "hidden",
                "description" => lang("Whether this server plugin can set reseller accounts"),
                "value" => "0",
            ],
            lang("package_addons") => [
                "type" => "hidden",
                "description" => lang("Supported signup addons variables"),
                "value" => "",
            ],
            lang('package_vars') => [
                'type' => 'hidden',
                'description' => lang('Whether package settings are set'),
                'value' => '0',
            ],
            lang('package_vars_values') => [
                'type'        => 'hidden',
                'description' => lang('VM account parameters'),
                'value'       => array(
                  'type' => array(
                      'type'        => 'dropdown',
                      'multiple'    => false,
                      'getValues'   => 'getVTs',
                      'label'            => 'Virtualization Type',
                      'description'     => lang('Enter the type of VT for this package (qemu or lxc).'),
                      'description' => '',
                      'value'       => '',
                  ),
                  'node' => array(
                      'type'        => 'dropdown',
                      'multiple'    => false,
                      'getValues'   => 'getNodes',
                      'label'            => 'Node Name',
                      'description'     => lang('Select the node name where VM will be created.'),
                      'description' => '',
                      'value'       => '',
                  ),
                  'vmram' => array(
                      'type'            => 'text',
                      'label'            => 'VM Ram (MB)',
                      'description'     => lang('Enter the type of VM RAM in Megabyte.'),
                      'value'           => '1024',
                  ),
                  'vmcpu' => array(
                      'type'            => 'text',
                      'label'            => 'VM CPU (in Number)',
                      'description'     => lang('Enter the type of VM CPU in Number.'),
                      'value'           => '1',
                  ),
                  'vmdisk' => array(
                      'type'            => 'text',
                      'label'            => 'VM Disk Size (in GB)',
                      'description'     => lang('Enter the type of VM Disk Size in GB.'),
                      'value'           => '30',
                  ),
                ),
            ],
        ];

        return $variables;
    }

    public function getVTs()
    {
        $vts = [];
        $vts[0] = lang('-- Select VT Type --');
        $vts['qemu'] = "Qemu";
        $vts['lxc'] = "Lxc";
        return $vts;
    }

    public function getNodes($serverId)
    {
        $server = new Server($serverId);
        // hack to get hostname populated, this should be re-written.
        $server->getName();
        $pluginVariables = $server->getAllServerPluginVariables($this->user, 'proxmoxve');
        $this->api = new Proxmoxve(
            $pluginVariables['ServerHostName'],
            $pluginVariables['plugin_proxmoxve_Username'],
            "pam",
            $pluginVariables['plugin_proxmoxve_Password']
        );

        $plans = [];
        $plans[0] = lang('-- Select VPS Plan --');
        if ($this->api->login()) {
            foreach ($this->api->get_node_list() as $plan) {
                $plans[$plan] = $plan;
            }
        }
        return $plans;
    }

    public function validateCredentials($args)
    {
    }

    public function doUpdate($args)
    {
    }

    public function doDelete($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $args = $this->buildParams($userPackage);
        $this->delete($args);
        $vmHostname = $userPackage->getCustomField($args['server']['variables']['plugin_proxmoxve_VM_Hostname_Custom_Field'], CUSTOM_FIELDS_FOR_PACKAGE);
        return $vmHostname . ' has been deleted.';
    }

    public function delete($args)
    {
        $this->setup($args);
        if ($this->api->login()) {
            $stopVM =  $this->api->stop_vm($args['package']['variables']['type'], $args['package']['variables']['node'], $args['package']['ServerAcctProperties']);
            if ($stopVM) {
                sleep(15);
                $getVMInfo =  $this->api->current_vm($args['package']['variables']['type'], $args['package']['variables']['node'], $args['package']['ServerAcctProperties'])['status'];
                if ($getVMInfo == 'stopped') {
                    $destroy =  $this->api->destroy_vm($args['package']['variables']['type'], $args['package']['variables']['node'], $args['package']['ServerAcctProperties']);
                    if ($destroy) {
                        $userPackage = new UserPackage($args['package']['id']);
                        $VMIP = $userPackage->getCustomField($args['server']['variables']['plugin_proxmoxve_VM_MainIp_Custom_Field'], CUSTOM_FIELDS_FOR_PACKAGE);
                        $inSertquery = "INSERT INTO serverip (serverid, ip) VALUES(?, ?)";
                        $this->db->query($inSertquery, $args['server']['id'], $VMIP);

                        sleep(5);
                        $userPackage->setCustomField('Server Acct Properties', '');
                        $userPackage->setCustomField($args['server']['variables']['plugin_proxmoxve_VM_ID_Custom_Field'], "", CUSTOM_FIELDS_FOR_PACKAGE);
                        $userPackage->setCustomField($args['server']['variables']['plugin_proxmoxve_VM_Password_Custom_Field'], "", CUSTOM_FIELDS_FOR_PACKAGE);
                        $userPackage->setCustomField($args['server']['variables']['plugin_proxmoxve_VM_MainIp_Custom_Field'], "", CUSTOM_FIELDS_FOR_PACKAGE);
                        return true;
                    }
                }
            }
        }
    }

    public function doSuspend($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $args = $this->buildParams($userPackage);
        $this->setup($args);
        if ($this->api->login()) {
            $this->api->suspend_vm($args['package']['variables']['type'], $args['package']['variables']['node'], $args['package']['ServerAcctProperties']);
        }
        $vmHostname = $userPackage->getCustomField($args['server']['variables']['plugin_proxmoxve_VM_Hostname_Custom_Field'], CUSTOM_FIELDS_FOR_PACKAGE);
        return $vmHostname . ' has been suspended.';
    }

    public function doUnSuspend($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $args = $this->buildParams($userPackage);
        $this->setup($args);
        if ($this->api->login()) {
            $this->api->resume_vm($args['package']['variables']['type'], $args['package']['variables']['node'], $args['package']['ServerAcctProperties']);
        }
        $vmHostname = $userPackage->getCustomField($args['server']['variables']['plugin_proxmoxve_VM_Hostname_Custom_Field'], CUSTOM_FIELDS_FOR_PACKAGE);
        return $vmHostname . ' has been unsuspended.';
    }

    public function doReset($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $args = $this->buildParams($userPackage);
        $this->setup($args);
        if ($this->api->login()) {
            $this->api->reset_vm($args['package']['variables']['type'], $args['package']['variables']['node'], $args['package']['ServerAcctProperties']);
        }
        $vmHostname = $userPackage->getCustomField($args['server']['variables']['plugin_proxmoxve_VM_Hostname_Custom_Field'], CUSTOM_FIELDS_FOR_PACKAGE);
        return $vmHostname . ' has been reseted (Hard Reboot).';
    }

    public function doStart($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $args = $this->buildParams($userPackage);
        $this->setup($args);
        if ($this->api->login()) {
            $this->api->start_vm($args['package']['variables']['type'], $args['package']['variables']['node'], $args['package']['ServerAcctProperties']);
        }
        $vmHostname = $userPackage->getCustomField($args['server']['variables']['plugin_proxmoxve_VM_Hostname_Custom_Field'], CUSTOM_FIELDS_FOR_PACKAGE);
        return $vmHostname . ' has been booted.';
    }

    public function doStop($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $args = $this->buildParams($userPackage);
        $this->setup($args);
        if ($this->api->login()) {
            $this->api->stop_vm($args['package']['variables']['type'], $args['package']['variables']['node'], $args['package']['ServerAcctProperties']);
        }
        $vmHostname = $userPackage->getCustomField($args['server']['variables']['plugin_proxmoxve_VM_Hostname_Custom_Field'], CUSTOM_FIELDS_FOR_PACKAGE);
        return $vmHostname . ' has been shutdown.';
    }

    public function getAvailableActions($userPackage)
    {
        $args = $this->buildParams($userPackage);
        $this->setup($args);

        $actions = [];
        if ($args['package']['ServerAcctProperties'] == '') {
            $actions[] = 'Create';
        } else {
            $foundServer = false;
            if ($this->api->login()) {
                $servers = $this->api->current_vm($args['package']['variables']['type'], $args['package']['variables']['node'], $args['package']['ServerAcctProperties']);
            }

            if ($servers['status']) {
                    $foundServer = true;
                if ($servers['status'] == 'running') {
                    $actions[] = 'Reset';
                    $actions[] = 'Stop';
                    $actions[] = 'Delete';
                    $actions[] = 'Rebuild';
                } else {
                    $actions[] = 'Start';
                }
                if ($servers['qmpstatus'] == 'running') {
                    $actions[] = 'Suspend';
                } else {
                    $actions[] = 'UnSuspend';
                }
            }

            if ($foundServer == false) {
                $actions[] = 'Create';
            }
        }

        return $actions;
    }

    public function doRebuild($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $args = $this->buildParams($userPackage);
        $delVM = $this->delete($args);
        if ($delVM) {
            $this->create($args);
        }
        $vmHostname = $userPackage->getCustomField($args['server']['variables']['plugin_proxmoxve_VM_Hostname_Custom_Field'], CUSTOM_FIELDS_FOR_PACKAGE);
        return $vmHostname . ' has been reinstalled.';
    }

    public function doCreate($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $args = $this->buildParams($userPackage);
        $this->create($args);
        $vmHostname = $userPackage->getCustomField($args['server']['variables']['plugin_proxmoxve_VM_Hostname_Custom_Field'], CUSTOM_FIELDS_FOR_PACKAGE);
        return $vmHostname . ' has been created.';
    }

    public function create($args)
    {
        $this->setup($args);
        $userPackage = new UserPackage($args['package']['id']);
        $vmCloneId = $userPackage->getCustomField($args['server']['variables']['plugin_proxmoxve_VM_Operating_System_Custom_Field'], CUSTOM_FIELDS_FOR_PACKAGE);
        $hostname = $userPackage->getCustomField($args['server']['variables']['plugin_proxmoxve_VM_Hostname_Custom_Field'], CUSTOM_FIELDS_FOR_PACKAGE);
        if ($this->api->login()) {
            $vmId = $this->api->get_next_vmid();
            $vmCreate = $this->api->clone_vm($args['package']['variables']['type'], $args['package']['variables']['node'], $vmCloneId, $hostname, $vmId);
        }

        if ($vmCreate) {
            $userPackage->setCustomField('Server Acct Properties', $vmId);
            $userPackage->setCustomField($args['server']['variables']['plugin_proxmoxve_VM_ID_Custom_Field'], $vmId, CUSTOM_FIELDS_FOR_PACKAGE);

            sleep(10);
            if ($this->api->login()) {
                $this->api->vm_cpu_ram_set($args['package']['variables']['type'], $args['package']['variables']['node'], $vmId, $args['package']['variables']['vmram'], $args['package']['variables']['vmcpu']);
            }

            sleep(10);
            if ($this->api->login()) {
                $password = $this->generatePassword();
                $userPackage->setCustomField($args['server']['variables']['plugin_proxmoxve_VM_Password_Custom_Field'], $password, CUSTOM_FIELDS_FOR_PACKAGE);

                $serverId = $args['server']['id'];
                $query = "SELECT ip FROM serverip WHERE serverid=?";
                $result = $this->db->query($query, $serverId);
                $row = $result->fetch();
                $FinalIPAssign = $row[0];

                $userPackage->setCustomField($args['server']['variables']['plugin_proxmoxve_VM_MainIp_Custom_Field'], $FinalIPAssign, CUSTOM_FIELDS_FOR_PACKAGE);

                $editCloud =   $this->api->vm_cloudInit_set($args['package']['variables']['type'], $args['package']['variables']['node'], $vmId, $password, $FinalIPAssign, $args['server']['variables']['plugin_proxmoxve_Gateway_IP']);
                if ($editCloud) {
                  //Delete the IP
                    $Delquery = "DELETE FROM serverip WHERE ip=? AND serverid=? ";
                    $this->db->query($Delquery, $FinalIPAssign, $serverId);
                }
                sleep(15);
                $userPackage->setCustomField('IP Address', $FinalIPAssign);
                $userPackage->setCustomField('Username', "root");
                $userPackage->setCustomField('Password', $password);
                $this->api->start_vm($args['package']['variables']['type'], $args['package']['variables']['node'], $vmId);
            }
        }
    }

    public function generatePassword()
    {
        $_alphaSmall = "abcdefghAiSjDkFlGmHnJoKpZqXrCsVBtNuMvLwxyz";
        $_alphaCaps = strtoupper($_alphaSmall);
        $_numerics = "23456789";
        $_specialChars = "";
        $_container = $_alphaSmall . $_alphaCaps . $_numerics . $_specialChars;
        $password = "";
        for ($i = 0; $i < 12; $i++) {
            $_rand = rand(0, strlen($_container) - 1);
            $password .= substr($_container, $_rand, 1);
        }
        return $password;
    }

    public function testConnection($args)
    {
        CE_Lib::log(4, 'Testing connection to proxmoxve');
        $this->setup($args);
        if ($this->api->login()) {
            $response = $this->api->get_node_list();
        }
        CE_Lib::log(4, $response);
        if (!is_array($response)) {
            throw new CE_Exception($response);
        }
    }
}
