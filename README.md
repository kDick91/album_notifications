# album_notifications
Email notification for Nextcloud app for Photos/Memories shared albums
I don't know how to code good, sorry if it sucks

//INSTALL INSTRUCTIONS
1.  Clone the repo to your local machine
2.  Run 'composer require --dev nextcloud/ocp' (or just 'composer dump-autoload') to generate the vendor and composer files
3.  Copy the project folder to your Nextcloud /custom_apps/ folder
4.  Enable the app thru the admin panel or with the 'occ app:enable album_notifications' command
5.  Each user will now have a settings menu titled 'Album Notifications' that dispatches a daily notification if photos were added to any of the selected albums

//NOTES
1.  The Photos app is required
2.  Emails dispatch at 730p CST but this can be adjusted by modifying the UTC time in lib/BackgroundJob/DailyNotificationJob.php
