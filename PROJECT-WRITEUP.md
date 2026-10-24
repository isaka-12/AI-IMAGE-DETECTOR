# AI Image Detector

## Contents

1. [Overview](#1-overview)
2. [Requirements and dependencies](#2-requirements-and-dependencies)
3. [How it is built](#3-how-it-is-built)
4. [How to run it](#4-how-to-run-it)
5. [How it works](#5-how-it-works)

---

## 1. Overview

The AI Image Scanner is a web application that checks whether an image was made or edited by AI. It reads the
metadata inside an uploaded JPEG, PNG or WebP file, and can optionally run a pixel-analysis check. It then shows a
verdict, a score, and the evidence it found.

It is a LAMP application (Linux, Apache, MariaDB, PHP) packaged as a single Docker image:
[`isaka12/ai-image-detector-lamp`](https://hub.docker.com/r/isaka12/ai-image-detector-lamp).

---

## 2. Requirements and dependencies

### 2.1 Requirements

| Requirement | Details |
|---|---|
| Docker | Engine 20.10+ or Docker Desktop |
| Platform | `linux/amd64` |
| Disk | About 520 MB for the image, plus the database volume |
| Memory | 512 MB is enough |
| Network | Needed to pull the image. Also needed for pixel analysis, if used. |
| Browser | Any modern browser with JavaScript enabled |

### 2.2 Dependencies inside the image

| Package | Purpose |
|---|---|
| Ubuntu 24.04 | Base image |
| `apache2` | Web server, listens on port 8080 |
| `libapache2-mod-php` | PHP 8.3 running inside Apache |
| `php-mysql` | Database driver for MariaDB |
| `php-curl` | HTTPS client for the Sightengine API |
| `mariadb-server` | Stores the scan history |
| `openssl` | Generates the database password |
| `ca-certificates` | Certificate checks for HTTPS |

The application uses no third-party code libraries. All file parsers are written in plain PHP.

### 2.3 External services

| Service | Required | Used for |
|---|---|---|
| Docker Hub | Yes | Downloading the image |
| [Sightengine](https://sightengine.com) | No | Optional pixel-based AI detection (needs an account) |

---

## 3. How it is built

The image is built from `ubuntu:24.04` in these steps:

1. **Install packages.** Installs the packages listed in [2.2](#22-dependencies-inside-the-image) without
   recommended extras, then deletes the package cache.
2. **Configure Apache.**
   - disables unused modules (`autoindex`, `status`, `negotiation`, `cgi`) and enables `headers`
   - removes the default site
   - moves Apache to port **8080** and turns off port 443
3. **Add configuration files.**
   - an Apache virtual host that serves only `/var/www/public`
   - Apache security headers and request limits
   - PHP settings: 10 MB upload limit, risky functions disabled, errors logged and not shown
4. **Copy the application.**
   - web pages and endpoints go to `/var/www/public`
   - detection logic goes to `/var/www/app`, which is outside the web root
   - both are owned by `root` and are read-only for the web server
5. **Set the runtime.** Declares a volume at `/var/lib/mysql`, exposes port `8080` and sets `entrypoint.sh` as the
   entrypoint.

At startup, the entrypoint does the following:

1. Starts MariaDB on a local socket only (no network port), and sets up its data directory on first run.
2. Creates a new random database password, the `scanner` database and user, and the `scans` table.
3. Reads the optional Sightengine keys from `/run/secrets/scanner.env`.
4. Starts Apache in the foreground.

---

## 4. How to run it

### 4.1 Pull the image

```bash
docker pull isaka12/ai-image-detector-lamp:latest
```

### 4.2 Run

```bash
docker run -d --name ai-scanner -p 127.0.0.1:8090:8080 \
  --cap-drop ALL --cap-add CHOWN --cap-add SETUID --cap-add SETGID --cap-add DAC_OVERRIDE \
  --security-opt no-new-privileges --pids-limit 200 --memory 512m \
  -v scanner-db:/var/lib/mysql \
  isaka12/ai-image-detector-lamp:latest
```

Open **<http://localhost:8090>**. The first start takes a few seconds while the database is set up.

| Option | Purpose |
|---|---|
| `-p 127.0.0.1:8090:8080` | Makes the app available on this machine only, at port 8090 |
| `--cap-drop ALL` + four `--cap-add` | Keeps only the Linux capabilities the container needs |
| `--security-opt no-new-privileges` | Stops processes from gaining extra privileges |
| `--pids-limit 200`, `--memory 512m` | Limits the number of processes and the memory |
| `-v scanner-db:/var/lib/mysql` | Keeps the scan history when the container is removed |

### 4.3 Run with pixel analysis (optional)

1. Create a `.env` file with your Sightengine account details:

   ```ini
   SIGHTENGINE_ENDPOINT=https://api.sightengine.com/1.0/check.json
   API_USER=your_api_user
   SIGHTENGINE_API_KEY=your_api_secret
   ```

2. Mount it read-only into the container:

   ```bash
   chmod 600 .env
   docker run -d --name ai-scanner -p 127.0.0.1:8090:8080 \
     --cap-drop ALL --cap-add CHOWN --cap-add SETUID --cap-add SETGID --cap-add DAC_OVERRIDE \
     --security-opt no-new-privileges --pids-limit 200 --memory 512m \
     -v scanner-db:/var/lib/mysql \
     -v "$PWD/.env:/run/secrets/scanner.env:ro" \
     isaka12/ai-image-detector-lamp:latest
   ```

3. On the dashboard, tick **pixel analysis** before you upload an image.

### 4.4 Use it

1. Open <http://localhost:8090>.
2. Click **Upload image**, or drag and drop a JPEG, PNG or WebP file (up to 10 MB).
3. Read the result: the score, the verdict, the evidence list and the metadata panel.
4. Past scans appear in the history table. You can filter them, delete one, or clear them all.

### 4.5 Manage the container

```bash
docker logs -f ai-scanner      # view logs
docker restart ai-scanner      # restart
docker rm -f ai-scanner        # stop and remove (history stays in the volume)
docker volume rm scanner-db    # delete the scan history
```

---

## 5. How it works

### 5.1 Components

| Component | Role |
|---|---|
| Web interface | Dashboard for uploading images and viewing results and history |
| `scan.php` | Receives an upload, runs the detection, saves the result |
| `history.php` | Returns statistics and the latest 50 scans |
| `delete.php` | Deletes one scan or the whole history |
| Detection engine | Reads the image file and applies the detection rules |
| MariaDB | Stores the scan history |

### 5.2 Processing a scan

1. **Upload.** The browser sends the image to `scan.php`, together with a security token.
2. **Validation.** The file must be 1 byte to 10 MB, and its real type must be JPEG, PNG or WebP. The type is
   checked from the file content, not the file name.
3. **Reading the file.** The engine walks the file's internal structure and extracts the metadata blocks. Pixels are
   never decoded.
4. **Parsing.** Each block is parsed into fields:
   - **EXIF:** camera make, model, exposure, software
   - **IPTC and XMP:** author, credit, AI source declarations
   - **C2PA:** Content Credentials manifest
   - **PNG text:** settings written by AI generators such as Stable Diffusion and ComfyUI
5. **Rules.** Each finding becomes a piece of evidence with a strength: *strong*, *medium*, *weak* or *camera*.
6. **Pixel analysis (optional).** The image is sent to Sightengine, which returns an AI probability.
7. **Verdict.** The strongest evidence decides the result.
8. **Saving.** The file hash, size, verdict and evidence are saved to the database. The image itself is not stored.
9. **Display.** The browser shows the score, the verdict, the evidence and every metadata field that was read.

### 5.3 Evidence

| Evidence | Strength |
|---|---|
| File declares it is AI-generated (C2PA or IPTC `DigitalSourceType`) | strong |
| AI generator settings or prompt found in the file | strong |
| AI tool named as software, author or credit, or a "Made with Google AI" label | medium |
| Pixel analysis ≥ 90% | medium |
| Pixel analysis 50–89%, or a typical AI image size (e.g. 1024×1024) | weak |
| Camera make, model and exposure data | camera |

### 5.4 Verdicts

| Verdict | Condition | Score |
|---|---|---|
| AI-generated | Strong evidence | 95% |
| Likely AI | Medium evidence | 75% |
| Weak hints | Weak evidence only | 55% |
| Camera photo | Camera data, no AI evidence | 15% |
| Inconclusive | No evidence found | N/A |

When pixel analysis is used, its measured probability replaces the fixed score. A file that declares itself
AI-generated always scores at least 95%.
