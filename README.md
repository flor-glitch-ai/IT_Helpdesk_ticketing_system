# IT Help Desk Ticketing System

A plain PHP, HTML, CSS, and vanilla JavaScript implementation for the existing project architecture.

## Architecture
- Windows browser accesses the site.
- Apache and PHP run inside WSL Ubuntu 20.04.
- MySQL runs only inside VirtualBox Ubuntu 20.04.
- PHP connects to the VirtualBox MySQL server through PDO over the network.
- Database name: `erp_helpdesk`.

## Included MVP features
- Login and logout
- First administrator setup
- Role-based access: `admin`, `it_staff`, `user`
- Dashboard summaries
- Submit and view tickets
- Ticket assignment, status and priority updates
- Ticket comments
- Ticket history
- Admin account creation
- Audit log and in-app notification records

## Database compatibility
This project targets the 15-table schema in `it_helpdesk_schema_no_departments.sql`:
- roles
- users
- tickets
- ticket_comments
- ticket_attachments
- ticket_history
- assets
- repair_history
- maintenance_records
- approvals
- notifications
- knowledge_base
- satisfaction_ratings
- audit_logs
- system_settings

It intentionally does not use a `departments` table.

Important column names used by the PHP code:
- `users.id`, `first_name`, `last_name`, `email`, `password`, `role_id`, `is_active`
- `roles.id`, `name`
- `tickets.id`, `ticket_number`, `title`, `description`, `category`, `priority`, `status`, `requester_id`, `assigned_to`
- `ticket_comments.id`, `ticket_id`, `user_id`, `comment`
- `ticket_history.id`, `ticket_id`, `changed_by`, `field_changed`, `old_value`, `new_value`

## 1. Configure database connection
Edit `config/database.php`:
- `DB_HOST`: VirtualBox Ubuntu VM IP address reachable from WSL
- `DB_NAME`: keep `erp_helpdesk`
- `DB_USER`: MySQL user granted remote access from WSL
- `DB_PASS`: that MySQL user's password

Do not use `localhost` for DB_HOST unless MySQL is running in WSL (it should not be for this project).

## 2. Copy project into WSL
Extract the ZIP in WSL, then copy the folder into Apache's document root. Example:

```bash
sudo mkdir -p /var/www/html/erp-helpdesk
sudo cp -r it-helpdesk-system/. /var/www/html/erp-helpdesk/
sudo chown -R www-data:www-data /var/www/html/erp-helpdesk
sudo find /var/www/html/erp-helpdesk -type d -exec chmod 755 {} \;
sudo find /var/www/html/erp-helpdesk -type f -exec chmod 644 {} \;
```

If your extracted folder has a different path, adjust the source path.

## 3. Check PHP modules and Apache
```bash
sudo apt update
sudo apt install php libapache2-mod-php php-mysql
sudo systemctl restart apache2
```

If already installed, do not reinstall.

## 4. Test the connection
Open:
`http://localhost/erp-helpdesk/test_connection.php`

You should see “Database connection successful” and the table list.

If it fails:
- Check `DB_HOST`, username, and password.
- Check the VM MySQL service is running.
- Check MySQL is listening on the VM network interface and TCP port 3306 is allowed.
- Check the MySQL user is permitted to connect from the WSL network address.
- Do not expose MySQL port 3306 to the public internet.

## 5. Create the first administrator
Open:
`http://localhost/erp-helpdesk/setup_admin.php`

Create the administrator. This page inserts the three role names if they do not exist and creates the first admin using `password_hash()`.

After successful creation, delete `setup_admin.php`:

```bash
sudo rm /var/www/html/erp-helpdesk/setup_admin.php
```

Then sign in at:
`http://localhost/erp-helpdesk/login.php`

## 6. Add team members
Sign in as administrator, open User Management, and create IT Staff and User accounts.

## 7. Important notes
- The app stores ticket categories as text in `tickets.category`; it does not require a separate categories table.
- The app uses the status values from the revised schema: `Submitted`, `Reviewed`, `Assigned`, `In Progress`, `Pending User`, `Resolved`, `Closed`.
- The app uses priority values: `Low`, `Medium`, `High`, `Critical`.
- The setup assumes the 15-table schema was imported successfully. If your actual database still has the earlier five-table schema (`user_id`, `category_id`, `subject`, `status` values such as `Open`), do not run this code until the schema is aligned.
- `test_connection.php` should be deleted after testing.
- Set appropriate upload limits and validate uploaded files before implementing attachments. This MVP does not yet expose file upload forms.
- Use HTTPS and stronger deployment security before production use.
