(function(){
  function byId(id){ return document.getElementById(id); }

  function togglePassword(button){
    var targetId = button.getAttribute('data-toggle-password');
    var input = byId(targetId);
    if (!input) return;
    var visible = input.type === 'password';
    input.type = visible ? 'text' : 'password';
    button.classList.toggle('is-visible', visible);
    button.setAttribute('aria-pressed', visible ? 'true' : 'false');
    button.setAttribute('aria-label', visible ? 'Ocultar contraseña' : 'Mostrar contraseña');
    var text = button.querySelector('.toggle-password__text');
    if (text) text.textContent = visible ? 'Ocultar' : 'Mostrar';
  }

  function hasConsecutiveDigits(value){
    var groups = value.match(/\d+/g) || [];
    return groups.some(function(group){
      var run = 1;
      for (var i = 1; i < group.length; i++) {
        var diff = Number(group[i]) - Number(group[i - 1]);
        if (diff === 1 || diff === -1) {
          run += 1;
          if (run >= 3) return true;
        } else {
          run = 1;
        }
      }
      return false;
    });
  }

  function passwordState(value){
    return {
      min_length: value.length >= 10,
      uppercase: /[A-ZÁÉÍÓÚÜÑ]/.test(value),
      lowercase: /[a-záéíóúüñ]/.test(value),
      number: /\d/.test(value),
      special: /[^\p{L}\d\s]/u.test(value),
      no_sequence: !hasConsecutiveDigits(value)
    };
  }

  function bindForm(form){
    var passwordInput = byId(form.getAttribute('data-password-input')) || form.querySelector('input[name="password"], input[name="admin_pass"]');
    var confirmInput = byId(form.getAttribute('data-confirm-input')) || form.querySelector('input[name="password2"], input[name="admin_pass2"]');
    var requirementsRoot = form.querySelector('[data-password-requirements]');
    var message = form.querySelector('[data-password-match-message]');
    var submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
    var confirmTouched = false;

    if (!passwordInput) return;

    function refreshRules(){
      var state = passwordState(passwordInput.value || '');
      var allValid = true;
      var shouldShowRequirements = document.activeElement === passwordInput || (passwordInput.value || '').length > 0;

      if (requirementsRoot) {
        requirementsRoot.hidden = !shouldShowRequirements;
        requirementsRoot.querySelectorAll('[data-rule]').forEach(function(item){
          var rule = item.getAttribute('data-rule');
          var valid = !!state[rule];
          item.classList.toggle('is-valid', valid);
          item.classList.toggle('is-invalid', !valid);
          if (!valid) allValid = false;
        });
      } else {
        Object.keys(state).forEach(function(rule){
          if (!state[rule]) allValid = false;
        });
      }
      return allValid;
    }

    function refreshMatch(allValid){
      if (!confirmInput || !message) {
        return allValid;
      }

      var shouldShowMessage = confirmTouched || document.activeElement === confirmInput || confirmInput.value.length > 0 || allValid;
      message.hidden = !shouldShowMessage;

      if (!shouldShowMessage) {
        confirmInput.setCustomValidity('Confirmá la contraseña.');
        return false;
      }

      if (!confirmInput.value) {
        message.textContent = 'Repetí la contraseña para confirmar que coincide.';
        message.classList.remove('match-ok');
        message.classList.add('match-bad');
        confirmInput.setCustomValidity('Confirmá la contraseña.');
        return false;
      }
      if (passwordInput.value !== confirmInput.value) {
        message.textContent = 'Las contraseñas no coinciden.';
        message.classList.remove('match-ok');
        message.classList.add('match-bad');
        confirmInput.setCustomValidity('Las contraseñas no coinciden.');
        return false;
      }
      if (!allValid) {
        message.textContent = 'La confirmación está bien, pero todavía faltan requisitos de seguridad.';
        message.classList.remove('match-ok');
        message.classList.add('match-bad');
        confirmInput.setCustomValidity('La contraseña todavía no cumple todos los requisitos.');
        return false;
      }
      message.textContent = 'Las contraseñas coinciden y la contraseña es válida.';
      message.classList.remove('match-bad');
      message.classList.add('match-ok');
      confirmInput.setCustomValidity('');
      return true;
    }

    function refresh(){
      var allValid = refreshRules();
      var canSubmit = refreshMatch(allValid);
      if (!confirmInput) {
        canSubmit = allValid;
      }
      submitButtons.forEach(function(button){
        button.disabled = !canSubmit;
      });
    }

    passwordInput.addEventListener('input', refresh);
    passwordInput.addEventListener('focus', refresh);
    passwordInput.addEventListener('blur', refresh);

    if (confirmInput) {
      confirmInput.addEventListener('focus', function(){ confirmTouched = true; refresh(); });
      confirmInput.addEventListener('input', function(){ confirmTouched = true; refresh(); });
      confirmInput.addEventListener('blur', refresh);
    }

    refresh();
  }

  document.querySelectorAll('[data-toggle-password]').forEach(function(button){
    button.addEventListener('click', function(){ togglePassword(button); });
  });
  document.querySelectorAll('[data-password-pair]').forEach(bindForm);
})();
