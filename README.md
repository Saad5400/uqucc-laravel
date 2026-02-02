# UQU Computer College - Laravel

## Maintenance

### Downloading a local copy of the database:

1. Connect to the vps via ssh:
   ```bash
   ssh root@78.47.152.41
   ```

2. Locate the database container id:
   ```bash
   docker ps # 1b55b24c0223
   ```

3. Create a dump of the database:
   ```bash
   docker exec -it 1b55b24c0223 pg_dump -U postgres -d uqucc > postgres_backup.sql
    ```

4. Exit the vps:
   ```bash
   exit
   ```

5. Copy the dump and the storage to your local machine:
   ```bash
   scp root@78.47.152.41:./postgres_backup.sql .
    ```

6. Restore the dump to your local database:
   ```bash
   psql -U admin -h localhost -d uqucc -c "DROP SCHEMA public CASCADE; CREATE SCHEMA public;"
   psql -U admin -h localhost -d uqucc -f postgres_backup.sql
   ```
