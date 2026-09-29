package com.example.demo;

import java.lang.management.ManagementFactory;
import java.net.Inet4Address;
import java.net.InetAddress;
import java.net.NetworkInterface;
import java.nio.file.Files;
import java.nio.file.Path;
import java.time.Instant;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.Map;
import java.util.TreeMap;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.atomic.AtomicLong;

import jakarta.servlet.http.HttpServletRequest;

import org.springframework.boot.SpringBootVersion;
import org.springframework.http.CacheControl;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.bind.annotation.RestController;

// The page itself is src/main/resources/static/index.html (served at "/").
@RestController
public class HelloController {

    private static final AtomicLong REQUESTS = new AtomicLong();
    private static final Map<String, Long> FAIL_UNTIL = new ConcurrentHashMap<>(Map.of("live", 0L, "ready", 0L));

    @GetMapping("/api/info")
    public ResponseEntity<Map<String, Object>> info(HttpServletRequest req) throws Exception {
        REQUESTS.incrementAndGet();
        Runtime rt = Runtime.getRuntime();

        Map<String, Object> pod = new LinkedHashMap<>();
        pod.put("hostname", hostname());
        pod.put("ip", podIp());
        pod.put("namespace", namespace());
        pod.put("node", System.getenv("NODE_NAME"));

        Map<String, Object> build = new LinkedHashMap<>();
        build.put("name", System.getenv("OPENSHIFT_BUILD_NAME"));
        build.put("source", System.getenv("OPENSHIFT_BUILD_SOURCE"));
        build.put("reference", System.getenv("OPENSHIFT_BUILD_REFERENCE"));
        build.put("commit", System.getenv("OPENSHIFT_BUILD_COMMIT"));

        Map<String, Object> process = new LinkedHashMap<>();
        process.put("pid", ProcessHandle.current().pid());
        process.put("platform", System.getProperty("os.name") + " " + System.getProperty("os.arch"));
        process.put("cpus", rt.availableProcessors());
        process.put("memoryMB", (rt.totalMemory() - rt.freeMemory()) / 1048576);
        process.put("uptimeSeconds", ManagementFactory.getRuntimeMXBean().getUptime() / 1000);

        Map<String, Object> request = new LinkedHashMap<>();
        request.put("method", req.getMethod());
        request.put("host", req.getHeader("Host"));
        request.put("clientIp", req.getRemoteAddr());
        request.put("forwardedFor", req.getHeader("X-Forwarded-For"));
        request.put("forwardedProto", req.getHeader("X-Forwarded-Proto"));
        request.put("userAgent", req.getHeader("User-Agent"));

        Map<String, Object> body = new LinkedHashMap<>();
        body.put("stack", "Java");
        body.put("framework", "Spring Boot " + SpringBootVersion.getVersion());
        body.put("runtime", System.getProperty("java.version") + " (" + System.getProperty("java.vendor") + ")");
        body.put("accent", envOr("APP_COLOR", "#e76f00"));
        body.put("message", envOr("APP_MESSAGE", "Hello from Java (Spring Boot) on OpenShift!"));
        body.put("pod", pod);
        body.put("build", build);
        body.put("process", process);
        body.put("request", request);
        body.put("requests", REQUESTS.get());
        body.put("probes", probes());
        body.put("env", appEnv());
        body.put("time", Instant.now().toString());
        return ResponseEntity.ok().cacheControl(CacheControl.noStore()).body(body);
    }

    @GetMapping("/health")
    public ResponseEntity<Map<String, String>> health() {
        return probe("live");
    }

    @GetMapping("/ready")
    public ResponseEntity<Map<String, String>> ready() {
        return probe("ready");
    }

    @PostMapping("/api/fail/{probe:live|ready}")
    public Map<String, Object> fail(@PathVariable String probe, @RequestParam(defaultValue = "60") int seconds) {
        int s = Math.max(0, Math.min(seconds, 300));
        FAIL_UNTIL.put(probe, System.currentTimeMillis() + s * 1000L);
        System.out.printf("%s probe failing for %ds%n", probe, s);
        return Map.of("probe", probe, "seconds", s, "probes", probes());
    }

    @PostMapping("/api/stress")
    public Map<String, Object> stress(@RequestParam(defaultValue = "30") int seconds) throws Exception {
        int s = Math.max(0, Math.min(seconds, 60));
        System.out.printf("Burning CPU for %ds%n", s);
        long end = System.nanoTime() + s * 1_000_000_000L;
        double sink = 0;
        while (System.nanoTime() < end) {
            sink += Math.sqrt(Math.random());
        }
        return Map.of("seconds", s, "pod", hostname(), "sink", sink > 0);
    }

    private static ResponseEntity<Map<String, String>> probe(String name) {
        boolean ok = probes().get(name);
        return ResponseEntity.status(ok ? 200 : 503).body(Map.of("status", ok ? "UP" : "DOWN"));
    }

    private static Map<String, Boolean> probes() {
        long now = System.currentTimeMillis();
        return Map.of("live", now >= FAIL_UNTIL.get("live"), "ready", now >= FAIL_UNTIL.get("ready"));
    }

    // APP_* variables, with secret-looking names masked.
    private static Map<String, String> appEnv() {
        Map<String, String> out = new TreeMap<>();
        System.getenv().forEach((k, v) -> {
            if (k.startsWith("APP_")) {
                out.put(k, k.toUpperCase().matches(".*(SECRET|PASSWORD|TOKEN|KEY).*") ? "••••••••" : v);
            }
        });
        return out;
    }

    private static String envOr(String name, String fallback) {
        String v = System.getenv(name);
        return v == null || v.isEmpty() ? fallback : v;
    }

    private static String hostname() throws Exception {
        return InetAddress.getLocalHost().getHostName();
    }

    private static String podIp() throws Exception {
        for (NetworkInterface nic : Collections.list(NetworkInterface.getNetworkInterfaces())) {
            if (nic.isLoopback() || !nic.isUp()) {
                continue;
            }
            for (InetAddress addr : Collections.list(nic.getInetAddresses())) {
                if (addr instanceof Inet4Address) {
                    return addr.getHostAddress();
                }
            }
        }
        return null;
    }

    private static String namespace() {
        try {
            return Files.readString(Path.of("/var/run/secrets/kubernetes.io/serviceaccount/namespace")).trim();
        } catch (Exception e) {
            String ns = System.getenv("POD_NAMESPACE");
            return ns != null ? ns : System.getenv("OPENSHIFT_BUILD_NAMESPACE");
        }
    }
}
