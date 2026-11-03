"use strict";

const calendarElement = document.querySelector("#shared-calendar");
const calendarStatus = document.querySelector("#calendar-status");

if (calendarElement) {
    if (typeof FullCalendar === "undefined") {
        if (calendarStatus) {
            calendarStatus.dataset.state = "error";
            calendarStatus.textContent = "No se pudo cargar FullCalendar. Comprueba tu conexión e inténtalo de nuevo.";
        }
    } else {
        const calendar = new FullCalendar.Calendar(calendarElement, {
            initialView: "dayGridMonth",
            locale: "es",
            firstDay: 1,
            height: "auto",
            events: {
                url: "calendario_eventos.php",
                failure(error) {
                    if (calendarStatus) {
                        calendarStatus.dataset.state = "error";
                        calendarStatus.textContent = "No se pudieron cargar los eventos del calendario.";
                    }
                    console.error("No se pudieron cargar los eventos del calendario:", error);
                }
            }
        });

        calendar.render();
        document.addEventListener("control-horario-panel-change", (event) => {
            if (event.detail?.panelId === "calendar") {
                calendar.updateSize();
            }
        });
    }
}
