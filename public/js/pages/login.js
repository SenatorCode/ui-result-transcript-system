import { escapeHtml, icon, alertBox, sealMarkup } from '../components/ui.js';

export function render({ params }) {
  const route = location.hash.replace(/^#\/?/, '').split('?')[0];
  if (route === 'forgot-password') return forgot();
  if (route === 'reset-password') return reset(params.get('token') || '');
  const expired = new URLSearchParams(location.hash.split('?')[1] || '').has('expired');
  return `<div class="login-layout">
    <aside class="login-aside"><a class="login-brand" href="#/login">${sealMarkup('login-seal', 84, true)}<span>University of Ibadan</span></a><div class="login-copy"><h1>Results and transcripts, in one place.</h1><p>View your academic progress and request official transcripts securely.</p></div><div class="login-aside-foot">Student portal · Registry services</div></aside>
    <main class="login-main"><section class="login-card"><p class="eyebrow">Student access</p><h2>Welcome back</h2><p>Sign in with your matric number to continue.</p>
      ${expired ? alertBox('Your session expired. Sign in to return to where you left off.', 'warning') : ''}
      <form class="login-form" data-form="login" novalidate>
        <div class="field"><label for="matricNo">Matric number</label><input id="matricNo" name="matricNo" autocomplete="username" required placeholder="e.g. CSC/2023/001" aria-describedby="matricError"><span class="field-error" id="matricError" data-error-for="matricNo"></span></div>
        <div class="field"><label for="password">Password</label><div class="password-wrap"><input id="password" name="password" type="password" autocomplete="current-password" required aria-describedby="passwordError"><button class="password-toggle" type="button" data-action="toggle-password" aria-label="Show password" aria-pressed="false">${icon('eye')}</button></div><span class="field-error" id="passwordError" data-error-for="password"></span></div>
        <div class="form-options"><a class="forgot-link" href="#/forgot-password">Forgot password?</a></div>
        <div class="login-error" data-role="form-error" aria-live="polite"></div>
        <button class="btn btn-block" type="submit" data-role="submit">Sign in ${icon('arrow', 18)}</button>
      </form>
      <div class="auth-note">Your account is protected with a secure session. Never share your password.</div>
      <div class="login-links"><a href="../admin/">Admin login ${icon('arrow', 15)}</a><a href="mailto:registry@ui.edu.ng">Need help signing in?</a></div>
    </section></main>
  </div>`;
}

function forgot() {
  return `<div class="login-layout"><aside class="login-aside"><a class="login-brand" href="#/login">${sealMarkup('forgot-seal', 84, true)}<span>University of Ibadan</span></a><div class="login-copy"><h1>Get back into your account.</h1><p>We’ll send reset instructions if the account details match a student record.</p></div><div class="login-aside-foot">Student portal · Registry services</div></aside><main class="login-main"><section class="login-card"><p class="eyebrow">Account recovery</p><h2>Reset your password</h2><p>Enter your matric number or university email.</p><form class="login-form" data-form="forgot"><div class="field"><label for="resetIdentity">Matric number or email</label><input id="resetIdentity" name="emailOrMatric" autocomplete="username" required></div><div data-role="form-error" aria-live="polite"></div><button class="btn btn-block" type="submit" data-role="submit">Send reset instructions ${icon('arrow', 18)}</button></form><div class="login-links"><a href="#/login">${icon('arrow', 15)} Back to sign in</a></div></section></main></div>`;
}

function reset(token) {
  return `<div class="login-layout"><aside class="login-aside"><a class="login-brand" href="#/login">${sealMarkup('reset-seal', 84, true)}<span>University of Ibadan</span></a><div class="login-copy"><h1>Choose a new password.</h1><p>Use at least 8 characters. Your reset link can only be used once.</p></div><div class="login-aside-foot">Student portal · Registry services</div></aside><main class="login-main"><section class="login-card"><p class="eyebrow">Account recovery</p><h2>Set a new password</h2><form class="login-form" data-form="reset"><input type="hidden" name="token" value="${escapeHtml(token)}"><div class="field"><label for="newPassword">New password</label><input id="newPassword" name="newPassword" type="password" autocomplete="new-password" minlength="8" required></div><div class="field"><label for="confirmPassword">Confirm new password</label><input id="confirmPassword" name="confirmPassword" type="password" autocomplete="new-password" minlength="8" required></div><div data-role="form-error" aria-live="polite"></div><button class="btn btn-block" type="submit" data-role="submit">Update password ${icon('arrow', 18)}</button></form><div class="login-links"><a href="#/login">${icon('arrow', 15)} Back to sign in</a></div></section></main></div>`;
}
