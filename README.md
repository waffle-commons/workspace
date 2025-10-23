# Waffle Commons Workspace

**Important:** This repository is **NOT** a library intended for end-users of the Waffle framework. 
It is the central **development and integration environment** for contributors working on 
the Waffle framework (`waffle-commons/waffle`) and its related components (`waffle-commons/*`).

If you want to create a new application using the Waffle framework, please use the official skeleton (when available):

```shell
composer create-project waffle-commons/skeleton my-waffle-app # Not available yet
```

## Purpose

This workspace serves several key functions for Waffle development:
1. **Orchestration:** Manages the Docker development environment (FrankenPHP, PHP extensions, Xdebug, Composer).
2. **Local Linking:** Uses Composer path repositories to link your local clones of `waffle-commons/waffle`
and various `waffle-commons/*` packages together. This allows you to work on multiple packages simultaneously 
and test their integration without needing to publish them. 
3. **Integration Testing:** Provides a realistic application-like environment to run tests that span across the core 
framework and its components. 
4. **Consistency:** Ensures all contributors use the same base development environment.

## Prerequisites

 - Git 
 - Docker & Docker Compose 
 - Composer (for managing the workspace itself and potentially running commands locally)
 - PHP >= 8.4 (installed locally, mainly for Composer operations outside Docker if preferred)

## Setup

1. **Clone Repositories:** Clone this workspace repository and any Waffle repositories you intend to work on into 
the same parent directory. The recommended structure is:
    ```
    ~/waffle-commons/
    ├── workspace/     # <-- Clone this repository here
    ├── waffle/        # <-- Clone waffle/waffle here (fork the repo if working on it)
    ├── http/          # <-- Clone waffle-commons/http here (fork the repo if working on it)
    ├── yaml/          # <-- Clone waffle-commons/yaml here (fork the repo if working on it)
    └── ...            # etc.
    ```
    ```shell
    # Example cloning commands:
    mkdir ~/waffle-commons
    cd ~/waffle-commons
    git clone git@github.com:waffle-commons/workspace.git
    git clone git@github.com:waffle-commons/waffle.git
    # Clone other components as needed, e.g.:
    # git clone git@github.com:waffle-commons/http.git
    ```
2. **Configure Local Packages:** Edit the composer.json file within the ~/waffle-commons/workspace directory. 
Uncomment or add entries in the repositories section for all the `waffle-commons/waffle` and `waffle-commons/*`
packages you have cloned locally and want to link.
```json
{
    "name": "waffle-commons/workspace",
    // ...
    "require": {
        "php": "^8.4",
        "waffle-commons/waffle": "1.0.0-dev",
        "waffle-commons/yaml": "1.0.0-dev" // Example for yaml library
        // Add other waffle-commons packages you want to test here
        // "waffle-commons/http": "dev-main"
    },
    "repositories": [
        {
            "type": "path",
            "canonical": false,
            "url": "../waffle",
            "options": {
                "versions": { "waffle-commons/waffle": "1.0.0-dev" },
                "symlink": true
            }
        },
        // Add paths for your local clones following this pattern:
        {
            "type": "path",
            "canonical": false,
            "url": "../yaml",
            "options": {
                "versions": { "waffle-commons/yaml": "1.0.0-dev" },
                "symlink": true
            }
        }
    ],
  // ...
}
```
**Note:** The `"options": { "symlink": true }` is recommended for Composer 2.2+ for better performance. We also point
to `"options": "versions": { "waffle-commons/waffle": "1.0.0-dev" }` to ensure composer doesn't try to download the
latest unstable version from packagist (or another source) but the local version defined (and doesn't exists on packagist).
3. **Build and Start Docker Environment:** Navigate to the workspace directory and use Docker Compose:
```shell
cd ~/waffle-commons/workspace
docker compose build # Only needed initially or after Dockerfile changes
docker compose up -d
```
This will build the image based on `Dockerfile` and start the FrankenPHP container in the background. 
Your entire `~/waffle-commons/` directory will typically be mounted inside the container at `/waffle-commons`.
4. **Install Composer Dependencies (Inside Docker):** Run Composer within the running container to install 
5. dependencies and create the symlinks to your local packages:
```shell
docker exec waffle-dev composer install
# Or use 'composer update' if you need to update dependencies
# docker-compose exec workspace composer update
```
Check the `workspace/vendor/waffle-commons/` directory inside the container (or locally) – you should 
see symlinks pointing to `../waffle`, `../yaml`, etc.

## Usage
 - **Accessing the Web Server:** Visit `https://localhost:8443` (or the port mapped in docker-compose.yml) 
in your browser. This typically serves the public/ directory of the test application within the workspace.
 - **Running Commands (Composer Scripts, Tests):** Execute commands within the container using `docker-compose exec`:
```shell
# Run Waffle's Full test suite
docker exec waffle-dev -w /waffle-commons/workspace composer tests-commons # Not yet implemented
# Or run directly if the script is in waffle-commons/waffle/composer.json
docker exec waffle-dev -w /waffle-commons/waffle composer tests

# Run static analysis on Waffle
docker exec waffle-dev -w /waffle-commons/waffle composer mago

# Update dependencies
docker exec waffle-dev -w /waffle-commons/workspace composer update
```
_Tip:_ Add convenient scripts to `workspace/composer.json` to simplify running tests or analysis for different components.
 - **Accessing the Container Shell:**
```shell
docker exec -it -w /waffle-commons/workspace waffle-dev bash # Or sh
```
 - **Stopping the Environment:**
```shell
docker down
```

## Development Workflow

1. Start the Docker environment (`docker compose start`). 
2. Make code changes in the respective local repositories (`~/waffle-commons/waffle`, `~/waffle-commons/http`, etc.). 
3. Changes are automatically reflected inside the Docker container thanks to the volume mount. 
4. Run Composer commands, tests, static analysis, etc., using `docker exec waffle-dev ...`. 
5. Test the integrated behavior by accessing the web server or running specific integration scripts.

## Contribution

This repository is intended for contributors to the Waffle ecosystem **ONLY**. Please refer to the `CONTRIBUTING.md` 
file in the specific repository (`waffle-commons/waffle`, `waffle-commons/*`) you wish to contribute to.