"use strict";

if ("serviceWorker" in navigator) {
    window.addEventListener("load", () => {
        navigator.serviceWorker.register("./sw.js")
            .catch((error) => {
                console.error("No se pudo registrar el Service Worker:", error);
            });
    });
}

const root = document.documentElement;
const themeToggle = document.querySelector("#theme-toggle");
const themeLabel = document.querySelector("#theme-label");
const themeIcon = document.querySelector("#theme-icon");
const menuToggle = document.querySelector("#menu-toggle");
const sidebar = document.querySelector("#app-sidebar");
const sidebarClose = document.querySelector("#sidebar-close");

function updateThemeControl() {
    const isDark = root.dataset.theme === "dark";
    if (themeLabel) {
        themeLabel.textContent = isDark ? "Modo claro" : "Modo oscuro";
    }
    if (themeIcon) {
        themeIcon.textContent = isDark ? "☀" : "☾";
    }
    themeToggle?.setAttribute("aria-pressed", String(isDark));
}

themeToggle?.addEventListener("click", () => {
    const nextTheme = root.dataset.theme === "dark" ? "light" : "dark";
    root.dataset.theme = nextTheme;
    updateThemeControl();

    try {
        localStorage.setItem("control-horario-theme", nextTheme);
    } catch (error) {
        if (themeLabel) {
            themeLabel.textContent = nextTheme === "dark"
                ? "Modo oscuro (no guardado)"
                : "Modo claro (no guardado)";
        }
    }

    window.dispatchEvent(new CustomEvent("control-horario-theme-change", {
        detail: { theme: nextTheme }
    }));
});

updateThemeControl();

function closeSidebar() {
    sidebar?.classList.remove("is-open");
    menuToggle?.setAttribute("aria-expanded", "false");
    menuToggle?.setAttribute("aria-label", "Abrir menú");
}

menuToggle?.addEventListener("click", () => {
    const isOpen = sidebar?.classList.toggle("is-open") ?? false;
    menuToggle.setAttribute("aria-expanded", String(isOpen));
    menuToggle.setAttribute("aria-label", isOpen ? "Cerrar menú" : "Abrir menú");
});

sidebarClose?.addEventListener("click", closeSidebar);
document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") closeSidebar();
});

const panelLinks = [...(sidebar?.querySelectorAll('a[href^="#"]') ?? [])];
const panels = [...document.querySelectorAll(".seccion-panel")];
const firstPanelId = panelLinks
    .map((link) => link.getAttribute("href")?.slice(1))
    .find((id) => id && document.getElementById(id)?.classList.contains("seccion-panel"));

function showPanel(panelId, updateHistory = false) {
    const selectedPanel = document.getElementById(panelId);
    if (!selectedPanel?.classList.contains("seccion-panel")) {
        return false;
    }

    panels.forEach((panel) => {
        const isSelected = panel === selectedPanel;
        panel.classList.toggle("activo", isSelected);
        panel.setAttribute("aria-hidden", String(!isSelected));
    });

    panelLinks.forEach((link) => {
        const isSelected = link.getAttribute("href") === `#${panelId}`;
        link.classList.toggle("activo", isSelected);
        if (isSelected) {
            link.setAttribute("aria-current", "page");
        } else {
            link.removeAttribute("aria-current");
        }
    });

    if (updateHistory && window.location.hash !== `#${panelId}`) {
        window.history.pushState(null, "", `#${panelId}`);
    }

    return true;
}

panelLinks.forEach((link) => {
    link.addEventListener("click", (event) => {
        const panelId = link.getAttribute("href")?.slice(1);
        if (panelId && showPanel(panelId, true)) {
            event.preventDefault();
        }
        closeSidebar();
    });
});

const initialPanelId = window.location.hash.slice(1);
if (!showPanel(initialPanelId) && firstPanelId) {
    showPanel(firstPanelId);
}

window.addEventListener("popstate", () => {
    if (!showPanel(window.location.hash.slice(1)) && firstPanelId) {
        showPanel(firstPanelId);
    }
});

const dashboardDataElement = document.querySelector("#dashboard-data");
const workedCanvas = document.querySelector("#work-hours-chart");
const staffCanvas = document.querySelector("#staff-status-chart");

if (workedCanvas && staffCanvas && dashboardDataElement) {
    const chartError = document.querySelector("#dashboard-chart-error");
    let data;

    try {
        data = JSON.parse(dashboardDataElement.textContent);
    } catch (error) {
        if (chartError) {
            chartError.hidden = false;
            chartError.textContent = "No se pudieron leer los datos del dashboard.";
        }
    }

    if (data && !data.error && typeof Chart !== "undefined") {
        const colors = {
            text: () => getComputedStyle(root).getPropertyValue("--muted").trim(),
            grid: () => getComputedStyle(root).getPropertyValue("--border").trim()
        };
        const chartInstances = [];

        chartInstances.push(new Chart(workedCanvas, {
            type: "bar",
            data: {
                labels: data.work.labels,
                datasets: [
                    {
                        label: "Horas trabajadas",
                        data: data.work.worked,
                        backgroundColor: "#3156c9",
                        borderRadius: 6
                    },
                    {
                        label: "Horas extra",
                        data: data.work.overtime,
                        backgroundColor: "#e89b32",
                        borderRadius: 6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { labels: { color: colors.text() } },
                    tooltip: {
                        callbacks: {
                            label: (context) => `${context.dataset.label}: ${context.parsed.y} h`
                        }
                    }
                },
                scales: {
                    x: { ticks: { color: colors.text() }, grid: { color: colors.grid() } },
                    y: {
                        beginAtZero: true,
                        ticks: { color: colors.text() },
                        grid: { color: colors.grid() },
                        title: { display: true, text: "Horas", color: colors.text() }
                    }
                }
            }
        }));

        chartInstances.push(new Chart(staffCanvas, {
            type: "doughnut",
            data: {
                labels: ["Trabajando", "De vacaciones", "De baja", "Fuera de jornada / en pausa"],
                datasets: [{
                    data: [data.staff.working, data.staff.vacation, data.staff.sick, data.staff.off],
                    backgroundColor: ["#147d58", "#3156c9", "#d65353", "#aab5c5"],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: "64%",
                plugins: {
                    legend: {
                        position: "bottom",
                        labels: { color: colors.text(), padding: 18 }
                    }
                }
            }
        }));

        window.addEventListener("control-horario-theme-change", () => {
            for (const chart of chartInstances) {
                chart.options.plugins.legend.labels.color = colors.text();
                if (chart.options.scales) {
                    for (const scale of Object.values(chart.options.scales)) {
                        scale.ticks.color = colors.text();
                        scale.grid.color = colors.grid();
                        if (scale.title) scale.title.color = colors.text();
                    }
                }
                chart.update();
            }
        });
    } else if (chartError && !data?.error) {
        chartError.hidden = false;
        chartError.textContent = data?.error
            ? "No se pudieron cargar los datos de los gráficos."
            : "No se pudo cargar Chart.js. Comprueba la conexión e inténtalo más tarde.";
    }
}
