Block concurrent connections quiz access rule
=============================================

Requirements
------------
- Moodle 2.7 (build 2014051200) or later.

Installation
------------
Copy the onesession folder into your Moodle /mod/quiz/accessrule directory and 
visit your Admin Notification page to complete the installation.

Usage
-----
Check "Block concurrent connections" in quiz settings. First time student will access 
quiz attempt, session information (Moodle session, user agent, IP) will be recorded.
Then any student attempts to access this quiz attempt from other computer or device or 
even browser will be blocked. 

This is useful to prevent situation when someone helps
student to solve quiz by accessing quiz with students username/password from other 
computer. This is much simplier then set up subnel access rule - if teacher see that 
student is attempting quiz that means that no one is accessing this quiz attempt 
at the same time. 

In case when student accidentally closes browser or computer breaks
during quiz, teacher can use attempt review page to unlock this attempt and allow 
student to continue quiz attempt on other device. 

Any attempts to continue quiz from other device are logged, so teacher can see log 
to determine if someone tried to cheat.

Author
------
- Vadim Dvorovenko (Vadimon@mail.ru)

Links
-----
- Updates: https://moodle.org/plugins/view.php?plugin=quizaccess_onesession
- Latest code: https://github.com/vadimonus/moodle-quizaccess_onesession

Changes
-------
Release 0.9 (build 2016042100):
- Initial release.

Release 1.0 (build 2016042800):
- First stable version.
