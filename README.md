# OpenShift S2I test apps

| Folder       | Stack                  | S2I builder image (example) |
|--------------|------------------------|-----------------------------|
| nodejs-app   | Node.js + Express      | `nodejs:20-ubi9`            |
| php-app      | PHP 8.x (plain)        | `php:8.2-ubi9`              |
| java-app     | Java 17 + Spring Boot 3| `java:openjdk-17-ubi8`      |
| ruby-app     | Ruby + Sinatra + Puma  | `ruby:3.3-ubi9`             |

All apps listen on port **8080** and serve the same dashboard at `/`, with each
stack's own accent color. The page shows pod, runtime, S2I build and request
details, plus interactive demos:

| Demo              | What it shows                                                        |
|-------------------|----------------------------------------------------------------------|
| Load balancing    | Polls `/api/info` without cookies and charts which pod answered      |
| Health probes     | Makes `/health` or `/ready` return 503 for N seconds (restarts/endpoints) |
| CPU stress        | Burns one core for 10–60 s to trigger a HorizontalPodAutoscaler      |
| Configuration     | Lists `APP_*` env vars (secrets masked); `APP_MESSAGE`, `APP_COLOR`  |

Each card includes the matching `oc` commands, with the deployment name taken
from the pod hostname.

### Endpoints (identical in every stack)

| Method | Path                              | Purpose                                  |
|--------|-----------------------------------|------------------------------------------|
| GET    | `/`                               | Dashboard UI                             |
| GET    | `/api/info`                       | JSON with everything the UI shows        |
| GET    | `/health`                         | Liveness: 200 UP / 503 DOWN              |
| GET    | `/ready`                          | Readiness: 200 UP / 503 DOWN             |
| POST   | `/api/fail/{live\|ready}?seconds=N` | Fail that probe for N s (0 restores, max 300) |
| POST   | `/api/stress?seconds=N`           | Busy-loop the CPU for N s (max 60)       |

The Java app also keeps Spring's `/actuator/health`.

### Editing the UI

The page lives in `shared/index.html`. Each app is built on its own
(`--context-dir`), so every app needs its own copy. After you edit the page, run:

```bash
./sync-ui.sh   # copies it to nodejs-app/public, ruby-app/public, php-app/public, java-app/.../static
```

## Deploy from a Git repo (subfolder via --context-dir)

```bash
REPO=https://github.com/<you>/<repo>.git

oc new-app nodejs:20-ubi9~$REPO     --context-dir=nodejs-app --name=node-app
oc new-app php:8.2-ubi9~$REPO       --context-dir=php-app    --name=php-app
oc new-app java:openjdk-17-ubi8~$REPO --context-dir=java-app --name=java-app
oc new-app ruby:3.3-ubi9~$REPO      --context-dir=ruby-app   --name=ruby-app

for a in node-app php-app java-app ruby-app; do oc expose service/$a; done
oc get routes
```

## Deploy from local folder (binary build, no Git needed)

```bash
oc new-build --name=node-app nodejs:20-ubi9 --binary
oc start-build node-app --from-dir=./nodejs-app --follow
oc new-app node-app && oc expose service/node-app
```

(Repeat per folder, swapping name/image.) Check available image tags with
`oc get is -n openshift`.

## Try the demos

```bash
APP=node-app
oc set probe deployment/$APP --liveness  --get-url=http://:8080/health --period-seconds=10
oc set probe deployment/$APP --readiness --get-url=http://:8080/ready  --period-seconds=5
oc scale deployment/$APP --replicas=3
oc create configmap $APP-config --from-literal=APP_MESSAGE="Hi from a ConfigMap" --from-literal=APP_COLOR="#6f42c1"
oc set env deployment/$APP --from=configmap/$APP-config
oc set resources deployment/$APP --requests=cpu=100m
oc autoscale deployment/$APP --cpu-percent=50 --min=1 --max=5
```
