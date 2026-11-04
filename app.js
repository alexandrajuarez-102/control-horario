"use strict";

const entryButton = document.querySelector("#entry-button");
const pauseButton = document.querySelector("#pause-button");
const resumeButton = document.querySelector("#resume-button");
const exitButton = document.querySelector("#exit-button");
const recordsBody = document.querySelector("#records-body");
const statusMessage = document.querySelector("#status");
const dailySummary = document.querySelector("#daily-summary");
const workSummaryMonth = document.querySelector("#work-summary-month");
const workSummaryStatus = document.querySelector("#work-summary-status");
const workSummaryBody = document.querySelector("#work-summary-weeks");
const monthlyWorkedHours = document.querySelector("#monthly-worked-hours");
const monthlyWorkedDays = document.querySelector("#monthly-worked-days");
const monthlyOvertimeHours = document.querySelector("#monthly-overtime-hours");
const employeeDailyExport = document.querySelector("#employee-daily-export");
const employeeDailyExportDate = document.querySelector("#employee-daily-export-date");
const employeeDailyExportEnd = document.querySelector("#employee-daily-export-end");
const csrfTokenElement = document.querySelector('meta[name="csrf-token"]');
const csrfToken = csrfTokenElement?.content;
const attendanceButtons = [entryButton, pauseButton, resumeButton, exitButton];
const eventLabels = {
    entry: "Entrada",
    exit: "Salida",
    pausa: "Inicio de pausa",
    fin_pausa: "Fin de pausa"
};
const madridTime = new Intl.DateTimeFormat("es-ES", {
    dateStyle: "short",
    timeStyle: "medium",
    timeZone: "Europe/Madrid"
});

function madridDate() {
    const parts = new Intl.DateTimeFormat("en", {
        timeZone: "Europe/Madrid",
        year: "numeric",
        month: "2-digit",
        day: "2-digit"
    }).formatToParts(new Date());
    const values = Object.fromEntries(parts.map(({ type, value }) => [type, value]));
    return `${values.year}-${values.month}-${values.day}`;
}

function showStatus(message, state = "") {
    statusMessage.textContent = message;
    statusMessage.dataset.state = state;
}

function showRecords(records) {
    recordsBody.replaceChildren();
    const lastExit = [...records].reverse().find((record) => record.eventType === "exit" && record.workedSeconds !== null);
    if (lastExit) {
        const worked = formatDuration(lastExit.workedSeconds);
        const overtime = formatDuration(lastExit.overtimeSeconds || 0);
        dailySummary.textContent = lastExit.isOvertime
            ? `Jornada: ${worked}. Horas extra: ${overtime}.`
            : `Jornada completada: ${worked}. Sin horas extra.`;
        dailySummary.dataset.state = lastExit.isOvertime ? "overtime" : "success";
    } else {
        dailySummary.textContent = "Las horas trabajadas y las posibles horas extra se calcularán al fichar la salida.";
        dailySummary.dataset.state = "";
    }

    if (records.length === 0) {
        const row = document.createElement("tr");
        const cell = document.createElement("td");
        cell.colSpan = 3;
        cell.className = "empty-row";
        cell.textContent = "Todavía no hay registros para hoy.";
        row.append(cell);
        recordsBody.append(row);
        return;
    }

    for (const record of records) {
        const row = document.createElement("tr");
        const timeCell = document.createElement("td");
        const eventCell = document.createElement("td");
        const overtimeCell = document.createElement("td");
        timeCell.textContent = madridTime.format(new Date(record.recordedAtUtc));
        eventCell.textContent = record.automaticClosure
            ? "Salida automática (turno cerrado)"
            : eventLabels[record.eventType] || "Fichaje";
        if (record.eventType === "exit" && record.workedSeconds !== null) {
            overtimeCell.textContent = record.isOvertime
                ? `Horas extra: ${formatDuration(record.overtimeSeconds)}`
                : `Total: ${formatDuration(record.workedSeconds)}`;
            overtimeCell.dataset.state = record.isOvertime ? "overtime" : "success";
            if (record.isOvertime) {
                row.className = "overtime-row";
            }
        } else {
            overtimeCell.textContent = "—";
        }
        row.append(timeCell, eventCell, overtimeCell);
        recordsBody.append(row);
    }
}

function formatDuration(totalSeconds) {
    const seconds = Math.max(0, Number(totalSeconds) || 0);
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    return `${hours} h ${String(minutes).padStart(2, "0")} min`;
}

async function loadRecords() {
    const query = new URLSearchParams({ date: madridDate() });
    const response = await fetch(`records.php?${query}`, {
        headers: { Accept: "application/json" }
    });
    const result = await response.json();

    if (response.status === 401) {
        window.location.assign("login.php");
        return;
    }

    if (!response.ok) {
        throw new Error(result.message || "No se pudieron cargar los registros.");
    }

    showRecords(result.records);
}

async function loadWorkSummary() {
    if (!workSummaryMonth || !workSummaryMonth.value) return;
    workSummaryStatus.textContent = "Cargando resumen…";
    workSummaryBody.replaceChildren();

    try {
        const query = new URLSearchParams({ mes: workSummaryMonth.value });
        const response = await fetch(`resumen_empleado.php?${query}`, {
            headers: { Accept: "application/json" }
        });
        const result = await response.json();
        if (response.status === 401) {
            window.location.assign("login.php");
            return;
        }
        if (!response.ok) throw new Error(result.message || "No se pudo cargar el resumen.");

        monthlyWorkedHours.textContent = formatDuration(result.workedSeconds);
        monthlyWorkedDays.textContent = String(result.workedDays);
        monthlyOvertimeHours.textContent = formatDuration(result.overtimeSeconds);
        if (result.weeks.length === 0) {
            const row = document.createElement("tr");
            const cell = document.createElement("td");
            cell.colSpan = 3;
            cell.className = "empty-row";
            cell.textContent = "No hay jornadas cerradas en este mes.";
            row.append(cell);
            workSummaryBody.append(row);
        } else {
            for (const week of result.weeks) {
                const start = new Date(`${week.weekStart}T12:00:00Z`);
                const end = new Date(start);
                end.setUTCDate(end.getUTCDate() + 6);
                const row = document.createElement("tr");
                const weekCell = document.createElement("td");
                const daysCell = document.createElement("td");
                const hoursCell = document.createElement("td");
                weekCell.textContent = `Del ${madridTime.format(start).split(",")[0]} al ${madridTime.format(end).split(",")[0]}`;
                daysCell.textContent = String(week.days);
                hoursCell.textContent = formatDuration(week.worked);
                row.append(weekCell, daysCell, hoursCell);
                workSummaryBody.append(row);
            }
        }
        workSummaryStatus.textContent = `Resumen de ${workSummaryMonth.value}.`;
        delete workSummaryStatus.dataset.state;
    } catch (error) {
        workSummaryStatus.textContent = error.message || "No se pudo cargar el resumen.";
        workSummaryStatus.dataset.state = "error";
    }
}

async function registerEvent(eventType) {
    if (!csrfToken) {
        showStatus("No se pudo validar la sesión. Recarga la página.", "error");
        return;
    }

    attendanceButtons.forEach((button) => { button.disabled = true; });
    showStatus("Obteniendo ubicación para registrar el fichaje…");

    try {
        const position = await getCurrentPosition();
        const response = await fetch("registro.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-CSRF-Token": csrfToken
            },
            body: JSON.stringify({
                eventType,
                clientTimestamp: new Date().toISOString(),
                timeZone: Intl.DateTimeFormat().resolvedOptions().timeZone,
                latitud: position.coords.latitude,
                longitud: position.coords.longitude
            })
        });
        const result = await response.json();

        if (!response.ok) {
            throw new Error(result.message || "No se pudo guardar el fichaje.");
        }

        await loadRecords();
        showStatus(`${eventLabels[eventType]} registrada a las ${madridTime.format(new Date(result.record.recordedAtUtc))}.`, "success");
    } catch (error) {
        showStatus(error.message || "Error de conexión. Inténtalo de nuevo.", "error");
    } finally {
        attendanceButtons.forEach((button) => { button.disabled = false; });
    }
}

function getCurrentPosition() {
    return new Promise((resolve, reject) => {
        if (!navigator.geolocation) {
            reject(new Error("Este navegador no permite obtener la ubicación. No se puede registrar el fichaje."));
            return;
        }

        navigator.geolocation.getCurrentPosition(
            resolve,
            (error) => {
                const messages = {
                    1: "Debes permitir el acceso a la ubicación para fichar.",
                    2: "No se pudo determinar tu ubicación. Comprueba el GPS e inténtalo de nuevo.",
                    3: "Se agotó el tiempo para obtener tu ubicación. Inténtalo de nuevo."
                };
                reject(new Error(messages[error.code] || "No se pudo obtener la ubicación. No se puede registrar el fichaje."));
            },
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
        );
    });
}

entryButton.addEventListener("click", () => registerEvent("entry"));
pauseButton.addEventListener("click", () => registerEvent("pausa"));
resumeButton.addEventListener("click", () => registerEvent("fin_pausa"));
exitButton.addEventListener("click", () => registerEvent("exit"));

if (workSummaryMonth) {
    workSummaryMonth.value = madridDate().slice(0, 7);
    workSummaryMonth.addEventListener("change", loadWorkSummary);
    loadWorkSummary();
}

if (employeeDailyExport && employeeDailyExportDate && employeeDailyExportEnd) {
    employeeDailyExportDate.value = madridDate();
    employeeDailyExport.addEventListener("submit", () => {
        employeeDailyExportEnd.value = employeeDailyExportDate.value;
    });
}

loadRecords().catch((error) => {
    showRecords([]);
    showStatus(error.message || "No se pudieron cargar los registros.", "error");
});
