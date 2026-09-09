import React from 'react';
import { createRoot } from 'react-dom/client';
import { ToastContainer } from 'react-toastify';

// Bootstrap's JS bundle (dropdowns, offcanvas, collapse, tooltips…).
import 'bootstrap/dist/js/bootstrap.bundle.min.js';

import '../css/app.css';
import Root from './Root.jsx';

const container = document.getElementById('app');
const root = createRoot(container);

root.render(
    <React.StrictMode>
        <Root />
        <ToastContainer position="top-right" autoClose={3500} newestOnTop theme="colored" />
    </React.StrictMode>,
);
