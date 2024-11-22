def parse_rules(command):
    # Extract rules from the command
    rules = {}
    if 'language:' in command:
        rules['language'] = extract_value(command, 'language:')
    if 'file:' in command:
        rules['file'] = extract_value(command, 'file:')
    if 'account:' in command:
        rules['account'] = extract_value(command, 'account:')
    return rules

def extract_value(command, key):
    # Logic to extract value for a given key from the command
    return command.split(key)[1].split()[0]