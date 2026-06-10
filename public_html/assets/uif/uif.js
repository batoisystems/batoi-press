document.addEventListener("click", function (event) {
    var target = event.target;
    if (!(target instanceof HTMLInputElement)) {
        return;
    }
    if (target.classList.contains("bp-code-input")) {
        target.select();
    }
});
