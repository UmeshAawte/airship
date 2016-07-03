su postgres -c "createuser airship"
su postgres -c "createdb -O airship airship"
password=`php random_password.php`
su postgres -c "psql -c \"ALTER USER airship PASSWORD '$password'\""

token=`php get_url.php`
echo "Add this to the URL:"
echo -e "?token=$token"