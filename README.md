# Lala.ThrowableStorage

Group old Flow exception log files to zip files based on the date
of their occurance.

## Configuration
```yaml
Neos:
  Flow:
    log:
      throwables:
        optionsByImplementation:
          'Lala\ThrowableRotate\Log\RotatingFileStorage':
            # Directory to store exceptions in
            storagePath: '%FLOW_PATH_DATA%Logs/Exceptions'

            # Directory to store archives of old exceptions in
            archiveStoragePath: '%FLOW_PATH_DATA%Logs/Exceptions'

            # Amount of exceptions to keep outside of archives
            exceptionsToKeep: 50

            # Sets the threshold for when to archive all exceptions that exceed 'exceptionsToKeep'
            archiveThreshold: 10
```
