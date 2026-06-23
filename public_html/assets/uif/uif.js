function bpStartUif() {
    var uif = window.BatoiUIF || (typeof BatoiUIF !== "undefined" ? BatoiUIF : null);
    if (uif && typeof uif.autoStart === "function") {
        uif.autoStart();
    }
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", bpStartUif, { once: true });
} else {
    bpStartUif();
}

window.setTimeout(bpStartUif, 0);

document.addEventListener("click", function (event) {
    var target = event.target;
    if (!(target instanceof HTMLInputElement)) {
        return;
    }
    if (target.classList.contains("bp-code-input")) {
        target.select();
    }
});
