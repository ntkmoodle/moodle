<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Rule that blocks attempt to open same quiz attampt in other session
 *
 * @package    quizaccess_onesession
 * @copyright  2016 Vadim Dvorovenko <Vadimon@mail.ru>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/accessrule/accessrulebase.php');

/**
 * Rule class.
 *
 * @package    quizaccess_onesession
 * @copyright  2016 Vadim Dvorovenko <Vadimon@mail.ru>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quizaccess_onesession extends quiz_access_rule_base {

    /**
     * Return an appropriately configured instance of this rule, if it is applicable
     * to the given quiz, otherwise return null.
     * @param quiz $quizobj information about the quiz in question.
     * @param int $timenow the time that should be considered as 'now'.
     * @param bool $canignoretimelimits whether the current user is exempt from
     *      time limits by the mod/quiz:ignoretimelimits capability.
     * @return quiz_access_rule_base|null the rule, if applicable, else null.
     */
    public static function make(quiz $quizobj, $timenow, $canignoretimelimits) {
        global $DB;

        if (!empty($quizobj->get_quiz()->onesessionenabled)) {
            return new self($quizobj, $timenow);
        } else {
            return null;
        }
    }

    /**
     * Returns session hash based on moodle session, IP and browser info
     *
     * @return string
     */
    private function get_session_hash() {
        return md5(sesskey() . getremoteaddr() . $_SERVER['HTTP_USER_AGENT']);
    }

    /**
     * Returns session hash based on moodle session, IP and browser info
     *
     * @param string $hash
     * @return bool
     */
    private function validate_session_hash($hash) {
        if ($hash === $this->get_session_hash()) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Is check before attempt start is required.
     *
     * @param int|null $attemptid the id of the current attempt, if there is one,
     *      otherwise null.
     * @return bool whether a check is required before the user starts/continues
     *      their attempt.
     */
    public function is_preflight_check_required($attemptid) {
        global $DB;

        if (is_null($attemptid)) {
            return false;
        }
        // Do not lock preview. We cannot clear quizaccess_onesession_sess, because current_attempt_finished and event observers
        // are not called on preview finish.
        $attemptobj = quiz_attempt::create($attemptid);
        if ($attemptobj->is_preview()) {
            return false;
        }
        $session = $DB->get_record('quizaccess_onesession_sess', array('attemptid' => $attemptid));
        if (empty($session)) {
            $session = new stdClass();
            $session->quizid = $this->quiz->id;
            $session->attemptid = $attemptid;
            $session->sessionhash = $this->get_session_hash();
            $DB->insert_record('quizaccess_onesession_sess', $session);
            return false;
        } else if ($this->validate_session_hash($session->sessionhash)) {
            return false;
        } else {
            // Log error.
            $params = array(
                'objectid' => $attemptobj->get_attemptid(),
                'relateduserid' => $attemptobj->get_userid(),
                'courseid' => $attemptobj->get_courseid(),
                'context' => $attemptobj->get_quizobj()->get_context(),
                'other' => array(
                    'quizid' => $attemptobj->get_quizid()
                )
            );
            $event = \quizaccess_onesession\event\attempt_blocked::create($params);
            $event->trigger();

            // We do not need preflight form. Just error.
            print_error('anothersession', 'quizaccess_onesession', $this->quizobj->view_url());
        }
    }

    /**
     * Information, such as might be shown on the quiz view page, relating to this restriction.
     * There is no obligation to return anything. If it is not appropriate to tell students
     * about this rule, then just return ''.
     * @return mixed a message, or array of messages, explaining the restriction
     *         (may be '' if no message is appropriate).
     */
    public function description() {
        return get_string('studentinfo', 'quizaccess_onesession');
    }

    /**
     * Get block with unlock attempt link
     *
     * @param int $attemptid the id of the current attempt.
     */
    private function get_attempt_unlock_block($attemptid) {
        $block = new block_contents();
        $block->attributes['id'] = 'quizaccess_onesession_unlockblock';
        $block->title = get_string('unlockthisattempt_header', 'quizaccess_onesession');
        $url = new moodle_url('/mod/quiz/accessrule/onesession/unlock.php', array('attempt' => $attemptid, 'sesskey' => sesskey()));
        $link = html_writer::link($url, get_string('unlockthisattempt', 'quizaccess_onesession'));
        $block->content = $link;
        return $block;
    }

    /**
     * Sets up the attempt (review or summary) page with any special extra
     * properties required by this rule. securewindow rule is an example of where
     * this is used.
     *
     * @param moodle_page $page the page object to initialise.
     */
    public function setup_attempt_page($page) {
        global $DB;

        if (!has_capability('quizaccess/onesession:unlockattempt', $this->quizobj->get_context())) {
            return;
        }
        $attemptid = $page->url->param('attempt');
        if (empty($attemptid)) {
            return;
        }
        $attemptobj = quiz_attempt::create($attemptid);
        if ($attemptobj->is_preview()) {
            return;
        }
        if ($attemptobj->get_state() != quiz_attempt::IN_PROGRESS) {
            return;
        }
        if (!$DB->record_exists('quizaccess_onesession_sess', array('attemptid' => $attemptid))) {
            return;
        }
        $unlockblock = $this->get_attempt_unlock_block($attemptid);
        $regions = $page->blocks->get_regions();
        $page->blocks->add_fake_block($unlockblock, reset($regions));
    }

    /**
     * Add any fields that this rule requires to the quiz settings form. This
     * method is called from {@link mod_quiz_mod_form::definition()}, while the
     * security seciton is being built.
     * @param mod_quiz_mod_form $quizform the quiz settings form that is being built.
     * @param MoodleQuickForm $mform the wrapped MoodleQuickForm.
     */
    public static function add_settings_form_fields(
            mod_quiz_mod_form $quizform, MoodleQuickForm $mform) {
        global $DB;

        $pluginconfig = get_config('quizaccess_onesession');

        $mform->addElement('checkbox', 'onesessionenabled', get_string('onesession', 'quizaccess_onesession'));
        $mform->setDefault('onesessionenabled', $pluginconfig->defaultenabled);
        $mform->setAdvanced('onesessionenabled', $pluginconfig->defaultenabled_adv);
        $mform->addHelpButton('onesessionenabled', 'onesession', 'quizaccess_onesession');
    }

    /**
     * Save any submitted settings when the quiz settings form is submitted. This
     * is called from {@link quiz_after_add_or_update()} in lib.php.
     * @param object $quiz the data from the quiz form, including $quiz->id
     *      which is the id of the quiz being saved.
     */
    public static function save_settings($quiz) {
        global $DB;

        if (empty($quiz->onesessionenabled)) {
            $DB->delete_records('quizaccess_onesession', array('quizid' => $quiz->id));
            $DB->delete_records('quizaccess_onesession_sess', array('quizid' => $quiz->id));
        } else {
            if (!$DB->record_exists('quizaccess_onesession', array('quizid' => $quiz->id))) {
                $record = new stdClass();
                $record->quizid = $quiz->id;
                $record->enabled = 1;
                $DB->insert_record('quizaccess_onesession', $record);
            }
        }
    }

    /**
     * Delete any rule-specific settings when the quiz is deleted. This is called
     * from {@link quiz_delete_instance()} in lib.php.
     * @param object $quiz the data from the database, including $quiz->id
     *      which is the id of the quiz being deleted.
     * @since Moodle 2.7.1, 2.6.4, 2.5.7
     */
    public static function delete_settings($quiz) {
        global $DB;

        $DB->delete_records('quizaccess_onesession', array('quizid' => $quiz->id));
        $DB->delete_records('quizaccess_onesession_sess', array('quizid' => $quiz->id));
    }

    /**
     * Return the bits of SQL needed to load all the settings from all the access
     * plugins in one DB query. The easiest way to understand what you need to do
     * here is probalby to read the code of {@link quiz_access_manager::load_settings()}.
     *
     * If you have some settings that cannot be loaded in this way, then you can
     * use the {@link get_extra_settings()} method instead, but that has
     * performance implications.
     *
     * @param int $quizid the id of the quiz we are loading settings for. This
     *     can also be accessed as quiz.id in the SQL. (quiz is a table alisas for {quiz}.)
     * @return array with three elements:
     *     1. fields: any fields to add to the select list. These should be alised
     *        if neccessary so that the field name starts the name of the plugin.
     *     2. joins: any joins (should probably be LEFT JOINS) with other tables that
     *        are needed.
     *     3. params: array of placeholder values that are needed by the SQL. You must
     *        used named placeholders, and the placeholder names should start with the
     *        plugin name, to avoid collisions.
     */
    public static function get_settings_sql($quizid) {
        return array(
            'quizaccess_onesession.enabled onesessionenabled',
            'LEFT JOIN {quizaccess_onesession} quizaccess_onesession ON quizaccess_onesession.quizid = quiz.id',
            array());
    }
}
