"use strict";

const profileDialog = document.querySelector("#profile-dialog");
const profileForm = document.querySelector("#profile-form");
const profileStatus = document.querySelector("#profile-status");
const profileHistoryStatus = document.querySelector("#profile-history-status");
const profileMonth = document.querySelector("#profile-month");
const profileRecordsBody = document.querySelector("#profile-attendance-body");
const profileCsrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
let activeProfileId = null;

function setProfileStatus(element, message, isError = false) {
    if (!element) return;
    element.textContent = message;
    if (isError) {
        element.dataset.state = "error";
    } else {
        delete element.dataset.state;
    }
}

function formatDuration(seconds) {
    if (seconds === null || seconds === undefined) return "—";
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    return `${String(hours).padStart(2, "0")}:${String(minutes).padStart(2, "0")}`;
}

function renderProfileRecords(records) {
    profileRecordsBody.replaceChildren();
    if (records.length === 0) {
        const row = document.createElement("tr");
        const cell = document.createElement("td");
        cell.className = "empty-row";
        cell.colSpan = 5;
        cell.textContent = "No hay entradas ni salidas para este mes.";
        row.append(cell);
        profileRecordsBody.append(row);
        return;
    }

    for (const record of records) {
        const row = document.createElement("tr");
        for (const value of [
            record.fecha,
            record.tipo,
            record.hora,
            formatDuration(record.workedSeconds),
            formatDuration(record.overtimeSeconds)
        ]) {
            const cell = document.createElement("td");
            cell.textContent = value;
            row.append(cell);
        }
        profileRecordsBody.append(row);
    }
}

async function loadProfileHistory() {
    if (!activeProfileId || !profileMonth.value) return;
    setProfileStatus(profileHistoryStatus, "Cargando fichajes…");
    profileRecordsBody.replaceChildren();

    try {
        const query = new URLSearchParams({
            usuario_id: activeProfileId,
            mes: profileMonth.value
        });
        const response = await fetch(`perfil_usuario.php?${query}`, {
            headers: { Accept: "application/json" }
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || "No se pudo cargar el historial.");
        renderProfileRecords(result.records);
        setProfileStatus(profileHistoryStatus, `${result.records.length} fichaje(s) de entrada o salida.`);
    } catch (error) {
        console.error("No se pudo cargar el historial del trabajador:", error);
        setProfileStatus(
            profileHistoryStatus,
            error instanceof Error ? error.message : "No se pudo cargar el historial.",
            true
        );
    }
}

async function openProfile(employeeId) {
    activeProfileId = employeeId;
    profileForm.reset();
    profileForm.elements.usuario_id.value = employeeId;
    setProfileStatus(profileStatus, "Cargando perfil…");
    setProfileStatus(profileHistoryStatus, "");
    const now = new Date();
    profileMonth.value = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}`;
    profileRecordsBody.replaceChildren();
    profileDialog.showModal();

    try {
        const query = new URLSearchParams({ usuario_id: employeeId, mes: profileMonth.value });
        const response = await fetch(`perfil_usuario.php?${query}`, {
            headers: { Accept: "application/json" }
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || "No se pudo cargar el perfil.");

        profileForm.elements.nombre.value = result.employee.nombre;
        profileForm.elements.dni.value = result.employee.dni || "";
        profileForm.elements.direccion.value = result.employee.direccion || "";
        profileForm.elements.correo.value = result.employee.correo;
        document.querySelector("#profile-title").textContent = `Perfil de ${result.employee.nombre}`;
        setProfileStatus(profileStatus, "");
        renderProfileRecords(result.records);
        setProfileStatus(profileHistoryStatus, `${result.records.length} fichaje(s) de entrada o salida.`);
    } catch (error) {
        console.error("No se pudo cargar el perfil del trabajador:", error);
        setProfileStatus(
            profileStatus,
            error instanceof Error ? error.message : "No se pudo cargar el perfil.",
            true
        );
        setProfileStatus(profileHistoryStatus, "No se pudo cargar el historial.", true);
    }
}

document.addEventListener("click", (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const profileButton = target?.closest("[data-view-profile]");
    if (profileButton) {
        openProfile(profileButton.dataset.viewProfile);
        return;
    }
    if (target?.closest("[data-close-profile]")) {
        profileDialog.close();
    }
});

profileMonth?.addEventListener("change", loadProfileHistory);

profileForm?.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!profileCsrfToken) {
        setProfileStatus(profileStatus, "No se pudo validar la sesión. Recarga la página.", true);
        return;
    }

    const saveButton = document.querySelector("#profile-save");
    const formData = new FormData(profileForm);
    formData.set("csrf_token", profileCsrfToken);
    saveButton.disabled = true;
    setProfileStatus(profileStatus, "Guardando cambios…");

    try {
        const response = await fetch("perfil_usuario.php", {
            method: "POST",
            headers: { Accept: "application/json" },
            body: formData
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || "No se pudo guardar el perfil.");

        const employee = result.employee;
        const row = document.querySelector(`[data-user-row="${CSS.escape(String(employee.id))}"]`);
        if (row) {
            row.querySelector("[data-profile-name]").textContent = employee.nombre;
            row.querySelector("[data-profile-dni]").textContent = employee.dni || "—";
            row.querySelector("[data-profile-address]").textContent = employee.direccion || "—";
            row.querySelector("[data-profile-email]").textContent = employee.correo;
        }
        document.querySelector("#profile-title").textContent = `Perfil de ${employee.nombre}`;
        setProfileStatus(profileStatus, "Perfil actualizado correctamente.");
    } catch (error) {
        console.error("No se pudo guardar el perfil del trabajador:", error);
        setProfileStatus(
            profileStatus,
            error instanceof Error ? error.message : "No se pudo guardar el perfil.",
            true
        );
    } finally {
        saveButton.disabled = false;
    }
});
