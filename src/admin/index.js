/* global jQuery */
// Classic admin screens: colour pickers, and hiding the weekly fields of the classic
// schedule meta box when the event happens once.

(function ($) {
  $(function () {
    if ($.fn.wpColorPicker) $(".uaip-tt-color").wpColorPicker();

    const weekly = document.querySelector("[data-uaip-tt-weekly]");
    if (!weekly) return;
    const radios = document.querySelectorAll('input[name="uaip_tt[repeat]"]');
    const sync = () => {
      const checked = document.querySelector('input[name="uaip_tt[repeat]"]:checked');
      weekly.hidden = !!checked && checked.value === "none";
    };
    radios.forEach((r) => r.addEventListener("change", sync));
    sync();
  });
})(jQuery);
