<?php

/**
 * Plugin to manage mail copying on all-inkl servers in Roundcube.
 *
 * @version @package_version@
 * @author Dr. Tobias Quathamer
 *
 * Copyright © 2015 Dr. Tobias Quathamer
 *
 * For installation and configuration instructions see README.md.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see http://www.gnu.org/licenses/.
 */
class allinkl_copy extends rcube_plugin
{
    public $task = 'settings';
    private $kas_result = [];

    public function init()
    {
        $rcmail = rcmail::get_instance();

        $this->load_config();
        $this->add_texts('localization/');
        $this->add_hook('settings_actions', [$this, 'settings_actions']);
        $this->register_action('plugin.allinkl_copy', [$this, 'copy_init']);
        $this->register_action('plugin.allinkl_save', [$this, 'copy_save']);

        $this->include_script('allinkl_copy.js');
    }

    public function settings_actions($actions)
    {
        $actions['actions'][] = [
            'action' => 'plugin.allinkl_copy',
            'type' => 'link',
            'label' => 'allinkl_copy.copies',
            'title' => 'allinkl_copy.managecopies',
        ];
        return $actions;
    }

    public function copy_init()
    {
        $this->register_handler('plugin.body', [$this, 'copy_form']);

        $rcmail = rcmail::get_instance();
        $rcmail->output->set_pagetitle($this->gettext('managecopies'));

        $rcmail->output->send('plugin');
    }

    public function copy_form()
    {
        $rcmail = rcmail::get_instance();
        $rcmail->output->set_env('product_name', $rcmail->config->get('product_name'));

        $table = new html_table(['cols' => 2]);

        // Show current copies
        $field_id = 'copies';
        $input_copies = new html_inputfield([
            'name' => '_copies',
            'id' => $field_id,
            'size' => 50,
            'autocomplete' => 'off',
            'autofocus' => true,
            'placeholder' => 'mail@domain.de',
            'value' => $this->get_current_copies()
        ]);

        $table->add('title', html::label($field_id, rcube::Q($this->gettext('copy_adresses'))));
        $table->add(null, $input_copies->show());

        $out = html::div(['class' => 'box'],
            html::div(['id' => 'prefs-title', 'class' => 'boxtitle'], $this->gettext('managecopies')) .
            html::div(['class' => 'boxcontent'],
                $table->show() .
                html::p(null, $this->gettext('copyinformation')).
                html::p(
                    null,
                    $rcmail->output->button([
                        'command' => 'plugin.allinkl_save',
                        'type'    => 'input',
                        'class'   => 'button mainaction',
                        'label'   => 'save'
                    ])
                )
            )
        );

        $rcmail->output->add_gui_object('copyform', 'copy-form');

        return $rcmail->output->form_tag([
            'id'     => 'copy-form',
            'name'   => 'copy-form',
            'method' => 'post',
            'action' => './?_task=settings&_action=plugin.allinkl_save',
        ], $out);
    }

    public function copy_save()
    {
        $this->register_handler('plugin.body', [$this, 'copy_form']);

        $rcmail = rcmail::get_instance();
        $rcmail->output->set_pagetitle($this->gettext('managecopies'));

        $copies = trim(rcube_utils::get_input_value('_copies', rcube_utils::INPUT_POST));

        if ($this->set_current_copies($copies))
        {
            $rcmail->output->command('display_message', $this->gettext('successfullysaved'), 'confirmation');
        }
        // Important: wait a bit before returning, because otherwise
        // allinkl.com may respond with a flood_protection
        usleep(500000);
        $rcmail->overwrite_action('plugin.allinkl_copy');
        $rcmail->output->send('plugin');
    }

    private function get_current_copies()
    {
        if ($this->interact_with_kas('get_mailaccounts')) {
            // Search copies of mail account
            foreach ($this->kas_result as $mail_information) {
                if ($mail_information['mail_adresses'] == $_SESSION['username']) {
                    $this->kas_mail_login = $mail_information['mail_login'];
                    return $mail_information['mail_copy_adress'];
                }
            }
        }
    }

    private function set_current_copies($copies)
    {
        $parameters = [
            'mail_login' => $this->get_kas_mail_login(),
            'copy_adress' => $copies,
        ];
        return $this->interact_with_kas('update_mailaccount', $parameters);
    }

    private function get_kas_mail_login()
    {
        if ($this->interact_with_kas('get_mailaccounts')) {
            // Search internal username of mail account
            foreach ($this->kas_result as $mail_information) {
                if ($mail_information['mail_adresses'] == $_SESSION['username']) {
                    return $mail_information['mail_login'];
                }
            }
        }
    }

    private function interact_with_kas($request_type, $parameters = [])
    {
        $this->kas_result = [];
        $rcmail = rcmail::get_instance();
        $WSDL_AUTH = 'https://kasserver.com/schnittstelle/soap/wsdl/KasAuth.wsdl';
        $WSDL_API = 'https://kasserver.com/schnittstelle/soap/wsdl/KasApi.wsdl';
        // Create SOAP-Session to KAS-Server
        try {
            $SoapLogon = new SoapClient($WSDL_AUTH);
            $CredentialToken = $SoapLogon->KasAuth([
                'KasUser' => $rcmail->config->get('allinkl_copy_user'),
                'KasAuthType' => 'sha1',
                'KasPassword' => sha1($rcmail->config->get('allinkl_copy_passwd')),
                'SessionLifeTime' => 30,
                'SessionUpdateLifeTime' => 'Y'
            ]);
        } catch (SoapFault $fault) {
            raise_error([
                'code' => 600,
                'type' => 'php',
                'file' => __FILE__,
                'line' => __LINE__,
                'message' => 'Allinkl copy plugin: ' . $fault->faultstring
            ], true, false);
            $rcmail->output->command('display_message', $this->gettext('errnosoaplogon'), 'error');
            return false;
        }
        // Execute the request
        try {
            $SoapRequest = new SoapClient($WSDL_API);
            $req = $SoapRequest->KasApi(json_encode([
                'KasUser' => $rcmail->config->get('allinkl_copy_user'),
                'CredentialToken' => $CredentialToken,
                'KasRequestType' => $request_type,
                'KasRequestParams' => $parameters
            ]));
        } catch (SoapFault $fault) {
            raise_error([
                'code' => 600,
                'type' => 'php',
                'file' => __FILE__,
                'line' => __LINE__,
                'message' => 'Allinkl copy plugin: ' . $fault->faultstring
            ], true, false);
            // Use specific error messages for certain errors.
            if (strpos($fault->faultstring, 'in_progress') === 0) {
                $rcmail->output->command('display_message', $this->gettext('errpreviousinprogress'), 'error');
            }
            elseif (strpos($fault->faultstring, 'nothing_to_do') === 0) {
                $rcmail->output->command('display_message', $this->gettext('errnothingtodo'), 'notice');
            }
            elseif (strpos($fault->faultstring, 'copy_adress_syntax_incorrect') === 0) {
                $rcmail->output->command('display_message', $this->gettext('errsyntaxincorrect'), 'error');
            }
            else {
                $rcmail->output->command('display_message', $this->gettext('errbadrequest'), 'error');
            }
            return false;
        }
        $this->kas_result = $req['Response']['ReturnInfo'];
        return true;
    }
}
