import sqlite3

def create_post_merge_table():
    conn = sqlite3.connect('example.db')
    c = conn.cursor()
    c.execute('''CREATE TABLE post_merge_commands
                 (id INTEGER PRIMARY KEY, pr_id INTEGER, command TEXT)''')
    conn.commit()
    conn.close()
create_post_merge_table()
