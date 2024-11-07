def parse_command(command):
    if command.startswith('@gstraccini after merge'):
        return 'post-merge', command[len('@gstraccini after merge'):].strip()
    # existing parsing logic
    return 'normal', command
