import React, { useState, useEffect } from 'react';
import { Navigate } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { ROLE_ROUTES } from '../contexts/AuthContext';

const Home = () => {
  const { login, isAuthenticated, role, loginMutation } = useAuth();
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState('');
  const [currentTime, setCurrentTime] = useState('');
  const [currentDate, setCurrentDate] = useState('');

  const isSubmitting = loginMutation.isPending;

  useEffect(() => {
    const updateDateTime = () => {
      const now = new Date();
      setCurrentDate(now.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }));
      setCurrentTime(now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true }));
    };
    updateDateTime();
    const interval = setInterval(updateDateTime, 1000);
    return () => clearInterval(interval);
  }, []);

  if (isAuthenticated) {
    return <Navigate to={ROLE_ROUTES[role] || '/dashboard'} replace />;
  }

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    const user = username.trim();
    const pass = password;
    if (!user) {
      setError('Please enter your username.');
      return;
    }
    if (!pass) {
      setError('Please enter your password.');
      return;
    }
    try {
      await login({ username: user, password: pass });
    } catch (err) {
      const data = err?.response?.data;
      let msg = data?.message || err?.message || 'Login failed. Please try again.';
      const errors = data?.errors;
      if (errors && typeof errors === 'object' && !Array.isArray(errors)) {
        const parts = Object.values(errors).flat().filter(Boolean);
        if (parts.length) msg = parts.join(' ');
      }
      setError(msg);
    }
  };

  return (
    <div className="student-dashboard sd-login-page">
      <div className="sd-page-container">
        {/* ========== HEADER (same as Student Dashboard) ========== */}
        <header className="sd-header">
          <div className="sd-header-inner">
            <div className="sd-brand">
              <img src="/logo.png" alt="TMCC" className="sd-logo" onError={(e) => { e.target.style.display = 'none'; }} />
              <h1 className="sd-college-name">Trece Martires City College</h1>
            </div>
            <div className="sd-header-right">
              <div className="sd-datetime">
                <span className="sd-date">{currentDate}</span>
                <span className="sd-time">{currentTime}</span>
              </div>
            </div>
          </div>
        </header>

        {/* ========== LOGIN HERO (same style as sd-profile-hero) ========== */}
        <section className="sd-profile-hero">
          <div className="sd-profile-bg" aria-hidden="true" />
          <div className="sd-profile-inner sd-login-hero-inner">
            <div className="sd-login-logo-wrap">
              <img
                src="/logo.png"
                alt="TMCC Logo"
                className="sd-login-logo"
                onError={(e) => {
                  e.target.style.display = 'none';
                  if (e.target.nextSibling) e.target.nextSibling.style.display = 'flex';
                }}
              />
              <div className="sd-login-logo-fallback" style={{ display: 'none' }}>
                <svg viewBox="0 0 300 300" className="w-full h-full">
                  <circle cx="150" cy="150" r="145" fill="rgb(0, 166, 82)" stroke="#006b34" strokeWidth="4"/>
                  <circle cx="150" cy="150" r="125" fill="none" stroke="#fff" strokeWidth="3"/>
                  <circle cx="150" cy="150" r="110" fill="#fff"/>
                  <circle cx="150" cy="150" r="105" fill="#f0f0f0" stroke="#ddd" strokeWidth="1"/>
                  <circle cx="150" cy="100" r="25" fill="#FFD700"/>
                  {[0, 45, 90, 135, 180, 225, 270, 315].map((angle, i) => (
                    <line key={i} x1="150" y1="100" x2={150 + 40 * Math.cos((angle * Math.PI) / 180)} y2={100 + 40 * Math.sin((angle * Math.PI) / 180)} stroke="#FFD700" strokeWidth="3" />
                  ))}
                  <rect x="100" y="130" width="100" height="15" fill="#0038A8" rx="2"/>
                  <rect x="100" y="148" width="100" height="15" fill="#CE1126" rx="2"/>
                  <path d="M95 175 L120 155 L140 165 L160 145 L180 160 L205 175 Z" fill="#666" opacity="0.7"/>
                  <path d="M60 200 Q80 140 100 200" fill="none" stroke="rgb(0, 166, 82)" strokeWidth="4"/>
                  <path d="M55 195 Q75 135 95 195" fill="none" stroke="rgb(0, 166, 82)" strokeWidth="3"/>
                  <path d="M200 200 Q220 140 240 200" fill="none" stroke="rgb(0, 166, 82)" strokeWidth="4"/>
                  <path d="M205 195 Q225 135 245 195" fill="none" stroke="rgb(0, 166, 82)" strokeWidth="3"/>
                  <circle cx="90" cy="215" r="6" fill="#FFD700"/>
                  <circle cx="150" cy="230" r="6" fill="#FFD700"/>
                  <circle cx="210" cy="215" r="6" fill="#FFD700"/>
                  <defs>
                    <path id="topArc" d="M40,150 A110,110 0 0,1 260,150"/>
                  </defs>
                  <text fontSize="14" fontWeight="bold" fill="#fff" letterSpacing="3">
                    <textPath href="#topArc" startOffset="50%" textAnchor="middle">TRECE MARTIRES CITY COLLEGE</textPath>
                  </text>
                  <text x="150" y="260" textAnchor="middle" fontSize="22" fontWeight="bold" fill="rgb(0, 166, 82)">2009</text>
                </svg>
              </div>
            </div>
            <div className="bg-white/60 px-5 w-fit">
              <h2 className="sd-profile-name">STUDENT RECORDS</h2>
              <p className="sd-login-hero-desc">Sign in with your TMCC credentials to access the Automated Student Records Management System.</p>
            </div>
          </div>
        </section>

        {/* ========== LOGIN FORM SECTION ========== */}
        <section className="sd-content">
          <div className="sd-enrollment-section">
            <h2 className="sd-section-title sd-title-red !text-black">Please Login</h2>
            <div className="sd-login-form-box">
              <form
                className="sd-login-form"
                onSubmit={handleSubmit}
                noValidate
                aria-label="Sign in form"
              >
                <div className="sd-login-field">
                  <input
                    id="login-username"
                    type="text"
                    className="sd-login-input"
                    placeholder="Username"
                    value={username}
                    onChange={(e) => { setUsername(e.target.value); setError(''); }}
                    autoComplete="username"
                    required
                    aria-label="Username"
                    aria-invalid={!!error && !username.trim()}
                    disabled={isSubmitting}
                  />
                </div>

                <div className="sd-login-field">
                  <div className="relative flex w-full">
                    <input
                      id="login-password"
                      type={showPassword ? 'text' : 'password'}
                      className="sd-login-input sd-login-input-password"
                      placeholder="Password"
                      value={password}
                      onChange={(e) => { setPassword(e.target.value); setError(''); }}
                      autoComplete="current-password"
                      required
                      aria-label="Password"
                      aria-invalid={!!error && !password}
                      disabled={isSubmitting}
                    />
                    <div className="sd-login-password-toggle">
                      <button
                        type="button"
                        className="sd-login-toggle-btn"
                        onClick={() => setShowPassword((p) => !p)}
                        aria-label={showPassword ? 'Hide password' : 'Show password'}
                        title={showPassword ? 'Hide password' : 'Show password'}
                      >
                        {showPassword ? (
                          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" />
                            <line x1="1" y1="1" x2="23" y2="23" />
                          </svg>
                        ) : (
                          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                            <circle cx="12" cy="12" r="3" />
                          </svg>
                        )}
                      </button>
                    </div>
                  </div>
                  <p className="sd-login-hint">* Password is case sensitive</p>
                </div>

                <button
                  type="submit"
                  className="sd-login-submit"
                  aria-label="Sign in"
                  disabled={isSubmitting}
                >
                  <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                    <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                  </svg>
                  Sign in
                </button>
              </form>

              {error && (
                <p className="sd-login-error" role="alert">
                  {error}
                </p>
              )}
            </div>
          </div>
        </section>
      </div>
    </div>
  );
};

export default Home;
