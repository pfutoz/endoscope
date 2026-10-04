$env:PGPASSWORD = 'postgres'
$pgDump = 'C:\Program Files\PostgreSQL\18\bin\pg_dump.exe'
$pgRestore = 'C:\Program Files\PostgreSQL\18\bin\pg_restore.exe'

$backupFile = 'C:\Apache24\htdocs\endoscope\archive\local_backup_before_prod.dump'
$prodDump = 'C:\Apache24\htdocs\endoscope\archive\endoscope.dump'

Write-Host "Creating backup of current database..."
& $pgDump -U postgres -h 127.0.0.1 -p 5432 -d endoscope -F c -f $backupFile
Write-Host "Backup exit code: $LASTEXITCODE"

Write-Host "Restoring production dump ($prodDump)..."
& $pgRestore -U postgres -h 127.0.0.1 -p 5432 -d endoscope --clean --if-exists --no-owner --no-privileges $prodDump
Write-Host "Restore exit code: $LASTEXITCODE"
