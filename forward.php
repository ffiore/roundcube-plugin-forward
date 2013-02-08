<?php
/**
 * Forward
 *
 * Plugin for setting forward address.
 *
 * @version 0.2
 * @author Martin Petracek <pechy@bubakov.net>
 *
 */
class forward extends rcube_plugin {
    private $driver;
    private $username;
    public $task = 'settings';
    function init() {
        $rcmail = rcmail::get_instance();
        $this->load_config();
        //set $this->username
        $driver=$rcmail->config->get('forward_driver');
        $include="{$this->home}/drivers/{$driver}.inc.php";
        $class="forward_driver_{$driver}";
        include_once($include);
        $this->driver=new $class($rcmail->config->get("forward_{$driver}_construct"));
        $this->username = $_SESSION['username'];
        if (!preg_match('/@/', $this->username)) $this->username.= $rcmail->config->get('forward_domain'); //if domain part is missing - add it
        $this->add_texts('localization/');;
        $this->register_action('plugin.forward', array($this, 'forward_init'));
        $this->register_action('plugin.forward-save', array($this, 'forward_save'));
        $this->register_action('plugin.forward-delete', array($this, 'forward_delete'));
        $rcmail->output->add_label('forward');
        $this->include_script('forward.js');
    }
    function forward_init() {
        $this->add_texts('localization/');
        $this->register_handler('plugin.body', array($this, 'forward_form'));
        $rcmail = rcmail::get_instance();
        $rcmail->output->set_pagetitle($this->gettext('forward'));
        $rcmail->output->send('plugin');
    }
    function forward_save() {
        $rcmail = rcmail::get_instance();
        $this->add_texts('localization/');
        $this->register_handler('plugin.body', array($this, 'forward_form'));
        $rcmail->output->set_pagetitle($this->gettext('forward'));
        $forward = trim(strtolower(get_input_value('_new_forward', RCUBE_INPUT_POST, true)));
        $alreadythere = false; //flag - is this address alredy in DB?
        if ($forward == "") $rcmail->output->command('display_message', $this->gettext('invalidmail'), 'error');
        else {
            if (preg_match('/[a-z0-9.-]+@[a-z0-9-.]+.[a-z0-9-.]+/', $forward)) { //is string format valid?
                $list=$this->driver->get_list($this->username,$rcmail);
                if ((count($list) - 1) < $rcmail->config->get('forward_max_forwards')) { //check if there isn't too many address for redirecting
                    foreach($list as $rule) if ($rule == $forward) $alreadythere = true;
                    if (!$alreadythere) { //check if this is address isn't in DB already
                        if ($this->driver->add_new($this->username,$forward,$rcmail)) { //new address succesfully added
                            $rcmail->output->command('display_message', $this->gettext('successfullysaved'), 'confirmation');
                            write_log('forward', "user $this->username set new forward address to $forward"); //log success
                        } else $rcmail->output->command('display_message', $this->gettext('unsuccessfullysaved'), 'error');
                        if ($rcmail->config->get('forward_to_same_addr') AND count($list)==0) $this->driver->add_new($this->username,$this->username,$rcmail); //see config.inc.php.dist for meaning of this
                    } else $rcmail->output->command('display_message', $this->gettext('addressalreadythere'), 'error');
                } else $rcmail->output->command('display_message', $this->gettext('toomuchforwards'), 'error');
            } else $rcmail->output->command('display_message', $this->gettext('invalidmail'), 'error');
        }
        rcmail_overwrite_action('plugin.forward');
        $rcmail->output->send('plugin');
    }
    function forward_delete() {
        $rcmail = rcmail::get_instance();
        $this->register_handler('plugin.body', array($this, 'forward_form'));
        $this->add_texts('localization/');
        if ($this->driver->delete($this->username,urldecode($_GET['mail']),$rcmail)) {
            $rcmail->output->command('display_message', $this->gettext('successfullydeleted'), 'confirmation');
            write_log('forward', "user $this->username deleted forwarding mails to address" . urldecode($_GET['mail']));
        } else $rcmail->output->command('display_message', $this->gettext('unsuccessfullydeleted'), 'error');
        //check for next forwardings
        $list=$this->driver->get_list($this->username,$rcmail);
        if ($rcmail->config->get('forward_to_same_addr') AND count($list) == 1) $this->driver->delete($this->username,$this->username,$rcmail); //see config.inc.php.dist for meaning of this
        rcmail_overwrite_action('plugin.forward');
        $rcmail->output->send('plugin');
    }
    function forward_form() {
        $rcmail = rcmail::get_instance();
        $rcmail->output->add_label('forward.noaddressfilled'); //for correctly displaying alert if <input> is empty
        $table = new html_table(array('cols' => 2));
        $table->add('title', Q($this->gettext('newforwardrule') . ":"));
        $inputfield = new html_inputfield(array('name' => '_new_forward', 'id' => '_new_forward'));
        $table->add('', $inputfield->show(""));
        $table2 = new html_table(array('cols' => 2));
        $list=$this->driver->get_list($this->username,$rcmail);
        if (count($list)) {
            foreach ($list as $rule) {
                if ($rule == $this->username) continue;
                $table2->add('alias', $rule);
                $dlink = "<a href='./?_task=settings&_action=plugin.forward-delete&mail=" . urlencode($rule) . "' onclick=\"return confirm('" . $this->gettext('reallydelete') . "');\">" . $this->gettext('delete') . "</a>";
                $table2->add('title', $dlink);
            }
        } else $table2->add('title', Q($this->gettext('msg_no_stored_forwards')));
        $out = html::div(array('class' => 'box'), html::div(array('id' => 'prefs-title', 'class' => 'boxtitle'), $this->gettext('forward')) . html::div(array('class' => 'boxcontent'), $table->show() . html::p(null, $rcmail->output->button(array('command' => 'plugin.forward-save', 'type' => 'input', 'class' => 'button mainaction', 'label' => 'save')))));
        $out.= html::div(array('class' => 'box'), html::div(array('id' => 'prefs-title', 'class' => 'boxtitle'), $this->gettext('storedforwards')) . html::div(array('class' => 'boxcontent'), $table2->show()));
        $rcmail->output->add_gui_object('forwardform', 'forwardform');
        return $rcmail->output->form_tag(array('id' => 'forwardform', 'name' => 'forwardform', 'method' => 'post', 'action' => './?_task=settings&_action=plugin.forward-save',), $out);
    }
}
