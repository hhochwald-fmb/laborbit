# LabOrbit – Hinweise für die Entwicklung

## Versionierung

Die Datei `VERSION` im Hauptordner ist die einzige Quelle der Versionsnummer (z. B. `1.9.0`).
`includes/db.php` liest sie beim Start in die Konstante `APP_VERSION`.

## Neue Version veröffentlichen

```bash
# 1. Version erhöhen
echo 1.9.1 > VERSION

# 2. Committen und pushen
git commit -am "Release 1.9.1"
git push

# 3. GitHub-Release anlegen – der Tag MUSS zur VERSION-Datei passen
gh release create v1.9.1 --title "LabOrbit 1.9.1" --notes "Was ist neu …"
```

- Das Repository muss **öffentlich** sein, die GitHub-API wird ohne Token abgefragt
  (Limit: 60 Abfragen pro Stunde und Server-IP).
- Optional kann ein eigenes Paket als Release-Asset angehängt werden (`laborbit-1.9.1.zip`).
  Ist keins vorhanden, nimmt der Updater das automatisch erzeugte Quellcode-ZIP („zipball“).
- Die Release-Notizen (Markdown) werden im Admin-Bereich angezeigt (Überschriften, Listen,
  **fett**, `code`; max. 4000 Zeichen).

## Wie der Online-Updater arbeitet (`includes/updater.php`)

1. **Prüfen:** `GET https://api.github.com/repos/<owner>/<repo>/releases/latest`
   (Repo einstellbar unter Admin → Updates, Setting `update_repo`).
2. **Herunterladen** nach `storage/tmp/update-<zufall>.zip` – nur per https und nur von
   GitHub-Hosts, max. 40 MB.
3. **Paket prüfen**, bevor irgendetwas verändert wird: Paket-Ordner finden, VERSION gültig
   und nicht älter als installiert, Pflichtdateien mit erwartetem Inhalt
   (`UPDATER_REQUIRED_FILES`), Mindestanzahl Dateien, Schreibrechte.
4. **Backup** der zu ersetzenden Dateien nach `storage/backups/backup-<alteVersion>-<datum>.zip`.
5. **Installieren** (VERSION zuletzt), bei Schreibfehlern automatische Wiederherstellung,
   danach `opcache_reset()`.

Ein Update darf nur diese Dateien schreiben: PHP-Dateien im Hauptordner, `includes/`, `pages/`,
`assets/` sowie `VERSION`, `README.md`, `ENTWICKLUNG.md`, `.htaccess`.
**Nie** angefasst werden `config.php`, `uploads/` und `storage/`.
`setup.php`, `patch.php` und `apply_prefixes.php` werden nur aktualisiert, wenn sie auf dem
Server noch existieren (gelöschte bleiben gelöscht).

> **Achtung beim Umbenennen:** Die Liste `UPDATER_REQUIRED_FILES` prüft das *neue* Paket mit
> dem Code der *alten* Installation. Werden dort genannte Dateien oder Funktionen umbenannt,
> lehnen ältere Installationen das Update ab. Im Zweifel den alten Namen als Kommentar stehen lassen.

Neue Dateien in anderen Ordnern als den oben genannten werden **nicht** installiert – dann
`UPDATER_DIRS` erweitern (wirkt erst ab dem darauffolgenden Update).

## Datenbank-Migrationen (`includes/migrations.php`)

- `runMigrations(PDO $pdo)` enthält **idempotente** Schritte: vorher prüfen, ob Tabelle/Spalte
  existiert (`migAddColumn()`, `migAddForeignKey()`, `CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE`).
- Neue Schritte **unten anhängen**, bestehende nie umsortieren.
- Kein `ADD COLUMN IF NOT EXISTS` verwenden – das gibt es nur in MariaDB, nicht in MySQL.
- Am Ende wird `db_version = APP_VERSION` gespeichert.
- `autoMigrate()` läuft bei jedem Seitenaufruf (in `includes/db.php`): Weicht `db_version` von
  `APP_VERSION` ab, laufen die Migrationen einmal automatisch. Fehler gehen ins PHP-error_log.
- Von Hand: Admin → System → „Datenbank jetzt prüfen & updaten“ (oder `update.php`).
