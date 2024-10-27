A php app that allows for easy management of whitelists and blacklists in spamassassin.

Ensure /var/spamassassin/local.cf is owned by www-data.

Run sudo visudo and add this to the bottom of the file if you want changes to take effect immediately:
www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart spamassassin
