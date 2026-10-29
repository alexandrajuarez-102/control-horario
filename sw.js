"use strict";

const CACHE_NAME = "control-horario-static-v1";
const STATIC_FILES = [
    "manifest.json",
    "app-icon.svg",
    "dashboard.js",
    "app.js",
    "offline.html"
];
const scopeUrl = new URL(self.registration.scope);
const staticUrls = STATIC_FILES.map((file) => new URL(file, scopeUrl));
const offlineUrl = new URL("offline.html", scopeUrl);

self.addEventListener("install", (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(staticUrls.map((url) => url.href)))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener("activate", (event) => {
    event.waitUntil(
        caches.keys()
            .then((cacheNames) => Promise.all(
                cacheNames
                    .filter((cacheName) => cacheName.startsWith("control-horario-static-")
                        && cacheName !== CACHE_NAME)
                    .map((cacheName) => caches.delete(cacheName))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener("fetch", (event) => {
    const request = event.request;
    const requestUrl = new URL(request.url);

    if (request.method !== "GET" || requestUrl.origin !== scopeUrl.origin) {
        return;
    }

    if (request.mode === "navigate") {
        event.respondWith(
            fetch(request).catch(async () => {
                const cachedOfflinePage = await caches.match(offlineUrl.href);
                return cachedOfflinePage ?? new Response(
                    "No hay conexión. Vuelve a intentarlo cuando tengas acceso a Internet.",
                    { status: 503, headers: { "Content-Type": "text/plain; charset=utf-8" } }
                );
            })
        );
        return;
    }

    const isStaticFile = staticUrls.some((url) => url.pathname === requestUrl.pathname);
    if (!isStaticFile) {
        return;
    }

    event.respondWith(
        fetch(request).then(async (response) => {
            if (response.ok && response.type === "basic") {
                const cache = await caches.open(CACHE_NAME);
                await cache.put(request, response.clone());
            }
            return response;
        }).catch(async () => {
            const cachedResponse = await caches.match(request);
            if (cachedResponse) {
                return cachedResponse;
            }
            return new Response("Recurso no disponible sin conexión.", {
                status: 503,
                headers: { "Content-Type": "text/plain; charset=utf-8" }
            });
        })
    );
});
