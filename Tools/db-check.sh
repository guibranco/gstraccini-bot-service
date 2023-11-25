if [ $# -ne 3 ]; then
    echo "::error file=$0,line=$LINENO::Usage: $0 <host> <user> <database>"
    exit 1
fi

MYSQL_HOST="$1"
MYSQL_USER="$2"
MYSQL_DB="$3"

CONNECT=$(
    mysql -h "$MYSQL_HOST" --protocol tcp "--user=$MYSQL_USER" --batch --skip-column-names -e \
        "SHOW DATABASES LIKE '"$DBNAME"';" | grep "$DBNAME" >/dev/null
    echo "$?"
)

if [ $CONNECT -eq 1 ]; then
    MESSAGE=""
    echo "::error file=$0,line=$LINENO::The database does not exist or cannot be accessed using these credentials."
    exit 1
fi

PullRequets= $(mysql -h "$MYSQL_HOST" --protocol tcp "--user=$MYSQL_USER" "--database=$MYSQL_DB" -sse \
    "SELECT COUNT(*) FROM information_schema.tables WHERE \
     table_schema='$MYSQL_DB' AND table_name='pull_requests';")
Comments=$(mysql -h "$MYSQL_HOST" --protocol tcp "--user=$MYSQL_USER" "--database=$MYSQL_DB" -sse \
    "SELECT COUNT(*) FROM information_schema.tables WHERE \
     table_schema='$MYSQL_DB' AND table_name='pull_requests_comments';")

if [[ $PullRequets -eq 0 && $Comments -eq 0 ]]; then
    echo "::error file=$0,line=$LINENO::The github_pull_requests and github_pull_requests_comments tables does not exists."
    mysql -h "$MYSQL_HOST" --protocol tcp "--user=$MYSQL_USER" "--database=$MYSQL_DB" -e \
        "SELECT * FROM information_schema.tables;"
    exit 1
fi
