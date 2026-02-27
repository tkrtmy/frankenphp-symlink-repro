# FrankenPHP Bug Reproduction: Worker fails after symlink-based deployment

## Summary

When using symlink-based deployment (e.g., Capistrano/Deployer style), switching the symlink and calling `RestartWorkers` (via admin API) causes workers to fail with `Failed opening required '...old-release.../worker.php'`.

The issue stems from commit `e0f01d1` ("Handle symlinking edge cases #1660"), which added `filepath.EvalSymlinks()` in `newWorker()`. As a result, the resolved (real) path is stored in `worker.fileName` at startup, and `RestartWorkers()` reuses that path without re-resolving the symlink.

## Environment

- FrankenPHP: v1.11.2
- OS: Linux (aarch64), macOS (arm64)

## Directory structure

```bash
.
├── v1.11.1.Caddyfile          # for v1.11.1 (prior to #1660)
├── v1.11.2.Caddyfile          # for v1.11.2 (after #1660)
├── current -> releases/v1      # symlink (relative), switched to v2 on deploy
└── releases/
    ├── v1/
    │   └── worker.php
    └── v2/
        └── worker.php
```

## Steps to reproduce

### 1. Clone / enter this directory

```bash
cd /path/to/frankenphp-symlink-repro
```

Confirm the initial symlink state:

```bash
ls -la current
# current -> releases/v1
```

### 2. Start FrankenPHP via Docker

```bash
# v1.11.1 (prior to #1660 — works correctly)
docker run --rm --name fptest -v "$(pwd):/app" -p 8080:80 \
  dunglas/frankenphp:1.11.1 frankenphp run --config /app/v1.11.1.Caddyfile

# v1.11.2 (after #1660 — reproduces the issue)
docker run --rm --name fptest -v "$(pwd):/app" -p 8080:80 \
  dunglas/frankenphp:1.11.2 frankenphp run --config /app/v1.11.2.Caddyfile
```

At startup, `newWorker()` calls `filepath.EvalSymlinks("/app/current/worker.php")` and stores the **resolved real path** internally:

```bash
worker.fileName = "/app/releases/v1/worker.php"  ← symlink already resolved at startup
```

### 3. Confirm v1 is serving (in another terminal)

```bash
curl http://localhost:8080/
# {"release":"v1","worker_file":"/app/releases/v1/worker.php", ...}
```

### 4. Switch the symlink to v2 (simulates a deployment)

```bash
ln -sfn releases/v2 current
ls -la current
# current -> releases/v2
```

### 5. Trigger RestartWorkers

```bash
docker exec fptest curl -sf -X POST http://localhost:2019/frankenphp/workers/restart
```

### 6. Send a request — observe the bug

```bash
curl http://localhost:8080/
```

- **v1.11.1**: returns `{"release":"v2", ...}` — symlink switch picked up correctly ✅
- **v1.11.2**: still returns `{"release":"v1", ...}` — stale path used ❌

### 7. Delete releases/v1 (simulates old-release cleanup) — fatal crash

```bash
rm -rf releases/v1
docker exec fptest curl -sf -X POST http://localhost:2019/frankenphp/workers/restart
curl http://localhost:8080/   # connection error
```

Docker logs show the loop:

```bash
{"level":"error","logger":"frankenphp","msg":"PHP Fatal error: Failed opening required '/app/releases/v1/worker.php' (include_path='.:') in Unknown on line 0"}
{"level":"warn","logger":"frankenphp","msg":"worker script has failed on restart","worker":"/app/releases/v1/worker.php","failures":1}
{"level":"warn","logger":"frankenphp","msg":"worker script has failed on restart","worker":"/app/releases/v1/worker.php","failures":2}
...
```

The worker retries indefinitely with the stale v1 path.

### Cleanup

```bash
docker rm -f fptest
ln -sfn releases/v1 current   # restore
mkdir -p releases/v1 && cp releases/v2/worker.php releases/v1/worker.php
sed -i '' "s/v2/v1/" releases/v1/worker.php
```

## Root cause

### `newWorker()` resolves and stores the real path at startup

[worker.go](worker.go):

```go
func newWorker(o workerOpt) (*worker, error) {
    absFileName, err := filepath.EvalSymlinks(o.fileName)  // symlink resolved here
    ...
    w.fileName = absFileName  // real path stored permanently
```

### `RestartWorkers()` reuses the stale real path

[worker.go](worker.go):

```go
func RestartWorkers() {
    threadsToRestart := drainWorkerThreads()
    for _, thread := range threadsToRestart {
        thread.drainChan = make(chan struct{})
        thread.state.Set(state.Ready)  // reuses w.fileName as-is, no re-resolution
    }
}
```

`RestartWorkers()` does not re-create the `worker` struct or re-evaluate the symlink. The `worker.fileName` still holds the path resolved at startup time.

### `caddy/module.go` also resolves symlinks at `Provision()` time

[caddy/module.go](caddy/module.go):

```go
if *f.ResolveRootSymlink {
    for i, wc := range f.Workers {
        if filepath.IsAbs(wc.FileName) {
            resolvedPath, _ := filepath.EvalSymlinks(wc.FileName)
            f.Workers[i].FileName = resolvedPath  // written once at Provision time
        }
    }
}
```

This path is passed to `frankenphp.WithWorkers()` and then to `newWorker()` — both layers resolve symlinks at startup and neither updates on restart.

## Behavior prior to #1660

Before `e0f01d1`, `newWorker()` only called `fastabs.FastAbs()` without `filepath.EvalSymlinks()`. So `worker.fileName` kept the symlink path (e.g., `./current/worker.php`). On each worker restart, the OS resolved the symlink fresh, automatically picking up the new release.

## Expected behavior

After switching the symlink and calling `RestartWorkers`, workers should start using the new symlink target.
