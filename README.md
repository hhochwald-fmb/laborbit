# LabOrbit - Physiksammlungs-Manager

LabOrbit ist eine moderne, webbasierte Inventar- und Experiment-Verwaltung für physikalische Schulsammlungen. Die App ermöglicht Lehrkräften das einfache Planen und Dokumentieren von Experimenten, die Zuweisung von Geräten zu Rubriken und das Bereitstellen von Versuchsbeschreibungen samt Fotos, Videos, PDFs und Messergebnissen.

## 🚀 Funktionen
- **Benutzerverwaltung & Rollen:** Manager (Admin), Lehrer (Lesen/Schreiben), Viewer (nur Leserechte).
- **Geräte & Rubriken:** Zuordnen von hunderten Geräten zu Schränken und übersichtlichen Kategorien/Rubriken.
- **Experimente:** Erstellung und Dokumentation von Experimenten. Zuweisung zu Klassen (z.B. Klasse 8, Oberstufe) und Themengebieten (z.B. Mechanik, Optik).
- **Rich-Text-Editor (Trix):** Integriertes, dynamisches Formatieren der Experimenttexte direkt im Browser.
- **Medienverwaltung:** Umfassende Möglichkeit, Bilder, Videos (MP4, WebM), PDFs und CSV-Dateien als Messergebnisse an Experimente anzuhängen. Eigener Videoplayer inklusive!
- **Modernes UI/UX-Design:** Animierter Login-Screen, 5 auswählbare Farb-Themes (inklusive Dark-Mode-Support), gebaut mit TailwindCSS (v3 via CDN) und liebevollen Mikro-Animationen.

---

## 💻 Servervoraussetzungen
LabOrbit ist extrem leichtgewichtig konzipiert und kommt ohne schwerfällige Frameworks aus. Es benötigt lediglich:
- **Webserver:** Apache, Nginx o.ä. (MAMP/XAMPP ideal für Offline-/Lokalbetrieb)
- **PHP:** Version 8.0 oder neuer
- **Datenbank:** MySQL oder MariaDB
- **PHP-Erweiterungen:** `PDO`, `pdo_mysql`, `gd` (für Bildverarbeitung)
- **Schreibrechte:** Der Webserver muss Ordner erstellen und Dateien schreiben dürfen.
- **Umgebung:** `.user.ini` oder `php.ini` Settings für große Uploads (siehe Installation).

---

## 🛠️ Installationsanleitung
1. Lade alle Dateien auf deinen Webserver oder in deinen MAMP `htdocs`-Ordner (z.B. `/psm`).
2. **Ordner- & Schreibrechte (CHMOD):** 
   - Der Webserver (PHP) muss in der Lage sein, die Konfigurationsdatei zu schreiben. Gib dem Ordner `/includes/` temporär Schreibrechte (z.B. `777` oder `755`), damit das Setup-Skript die `config.php` anlegen kann.
   - Die Ordnerstruktur unter `/uploads/` wird beim Setup **automatisch erstellt**. Stelle sicher, dass der Hauptordner Schreibrechte besitzt.
3. Stelle sicher, dass du eine leere MySQL-Datenbank auf deinem Server zur Verfügung hast.
4. Rufe im Browser direkt die Datei `setup.php` auf (z.B. `http://localhost:8888/psm/setup.php`).
   - Das Skript fragt dich im ersten Schritt über ein bequemes Formular nach deinen Datenbank-Zugangsdaten. 
   - Es erstellt die `includes/config.php` vollautomatisch für dich und generiert die Datenbanktabellen.
   - Im zweiten Schritt wirst du gebeten, den Benutzernamen und das Passwort für deinen **Manager-Account** (Admin) festzulegen.
5. Sobald das Setup erfolgreich beendet wurde, wirst du zum Login weitergeleitet.
6. **WICHTIG (Sicherheit):** Lösche anschließend die Datei `setup.php` vom Server!
7. *Hinweis zu Uploads:* Die bereitgestellte `.user.ini`-Datei erhöht standardmäßig die maximal erlaubte Datei-Upload-Größe für Videos auf 500 MB. (Alternativ musst du dies direkt in der `php.ini` deines Servers einstellen).

---

---

## 🐳 Installation mit Docker

Die einfachste Methode, LabOrbit lokal oder auf einem eigenen Server zu betreiben — ohne manuelle PHP/MySQL-Konfiguration.

### Voraussetzungen
- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (Mac/Windows) oder Docker + Docker Compose (Linux)

### Schnellstart

```bash
# 1. Repository klonen
git clone https://github.com/DEIN-USERNAME/laborbit.git
cd laborbit

# 2. Container starten
docker compose up -d
```

Die App ist danach unter **http://localhost:8181** erreichbar.

### Ersteinrichtung

1. Öffne **http://localhost:8181/setup.php** im Browser
2. **Schritt 1:** Datenbankverbindung — die Felder sind bereits vorausgefüllt:
   - Host: `db`
   - Benutzer: `laborbit`
   - Passwort: `laborbit`
   - Datenbankname: `laborbit`
3. **Schritt 2:** Manager-Account anlegen (Benutzername + Passwort festlegen)
4. Fertig — du wirst zum Login weitergeleitet

> **Sicherheit:** Nach dem Setup die Datei `setup.php` aus dem Container löschen:
> ```bash
> docker compose exec app rm /var/www/html/setup.php
> ```

### Datenpersistenz

| Was | Wo |
|---|---|
| Datenbank | Docker Volume `laborbit_db_data` |
| Hochgeladene Dateien | Docker Volume `laborbit_uploads` |

Beide Volumes bleiben bei `docker compose down` erhalten. Nur `docker compose down -v` löscht sie.

### Nützliche Befehle

```bash
# Container stoppen
docker compose down

# Logs anzeigen
docker compose logs -f app

# Nach Code-Änderungen neu bauen
docker compose build --no-cache && docker compose up -d

# Datenbankzugang (z.B. für TablePlus/DBeaver)
# → Port 3306 in docker-compose.yml unter db: ports: freigeben
```

### Port ändern

Standard ist `8181`. In `docker-compose.yml` unter `app: ports:` anpassen:
```yaml
ports:
  - "WUNSCHPORT:80"
```

---

## 🔄 Updates einspielen
Wenn Aktualisierungen am LabOrbit-Code vorgenommen wurden, die Datenbank-Änderungen erfordern, musst du das System wie folgt updaten:
1. Lade die neuen Dateien (z.B. aus der `LabOrbit_Release.zip`) per FTP/Dateisystem auf deinen Server.
2. **WICHTIG:** Wähle beim Hochladen **nur** die Dateien und Ordner aus, die *nicht* deine eigenen Inhalte enthalten. 
   - Den Ordner `uploads/` habe ich aus der ZIP-Datei **komplett entfernt**. Das Setup-Skript erstellt diesen Ordner bei einer Erstinstallation automatisch. Bei einem Update bleibt dein existierender `uploads/`-Ordner auf dem Server somit vollkommen unberührt.
   - Deine Konfiguration `includes/config.php` wird von der ZIP-Datei nicht überschrieben, da sie dort nicht enthalten ist.
3. Logge dich im System als **Manager** ein.
4. Rufe im Browser manuell die Adresse auf: `http://deinserver/psm/update.php`.
4. Das Skript prüft, welche Version dein System aktuell hat, und transformiert die Datenbank sicher auf den neuesten Stand.
5. Es erscheint eine Erfolgsmeldung – fertig! Du kannst nun zum Dashboard wechseln.

---

## 🔑 Administrator-Passwort vergessen?

Wenn du als Manager (Admin) dein Passwort vergessen hast, gibt es einen "Notfall-Login" (Emergency Reset), der direkt über die Datei `includes/config.php` gesteuert wird. Hierfür benötigst du Dateizugriff auf dem Server.

1. Öffne die Datei `includes/config.php` mit einem Texteditor auf deinem Server.
2. Füge am Ende der Datei folgende Zeile hinzu:
   ```php
   define('EMERGENCY_ADMIN_PASS', 'DeinNeuesGeheimesPasswort123!');
   ```
3. Gehe zur Login-Seite von LabOrbit.
4. Gib bei "Benutzername" einen beliebigen Text ein (dieser wird ignoriert).
5. Gib bei "Passwort" exakt `DeinNeuesGeheimesPasswort123!` ein und drücke Enter.
6. Das System loggt dich automatisch in den **ersten Manager-Account** ein und **überschreibt** dessen Passwort sofort dauerhaft mit diesem neuen Notfall-Passwort.
7. **WICHTIG:** Gehe danach sofort wieder in die `includes/config.php` und lösche diese Zeile aus Sicherheitsgründen wieder!

## 📂 Datei- & Ordnerstruktur
- `/includes` - Core-Dateien, Datenbankverbindung, Authentifizierung (Login-Logik).
- `/pages` - Einzelne Unterseiten, die vom Dashboard per `?page=` dynamisch geladen werden (z.B. `dashboard.php`, `experiments.php`).
- `/uploads` - Die physischen Speicherorte für Nutzer-Medien (Bilder, PDFs, Videos). Muss Schreibrechte (`777` / `755`) für PHP besitzen!
- `index.php` - Der zentrale Einstiegspunkt (Routing & UI/Layout).
- `update.php` - Migrations-Tool für Datenbankänderungen nach Updates.
