import sqlite3

def trigger_post_merge(pr_id):
    conn = sqlite3.connect('example.db')
    c = conn.cursor()
    c.execute('SELECT command FROM post_merge_commands WHERE pr_id=?', (pr_id,))
    commands = c.fetchall()
    for command in commands:
        execute_command(command[0])
    conn.close()
