import SwalBase from 'sweetalert2';
import { toast } from 'react-toastify';

import { errorMessage } from './axios.js';

export { errorMessage };

/**
 * TransitFlow-themed SweetAlert2. Import this instead of `sweetalert2`
 * directly so every dialog picks up the app's card style, fonts and
 * Bootstrap-styled controls.
 */
export const swal = SwalBase.mixin({
    buttonsStyling: false,
    reverseButtons: true,
    customClass: {
        popup: 'tf-swal',
        title: 'tf-swal-title',
        htmlContainer: 'tf-swal-body',
        actions: 'tf-swal-actions',
        confirmButton: 'btn btn-primary px-4',
        denyButton: 'btn btn-outline-danger px-4',
        cancelButton: 'btn btn-light px-4',
        input: 'form-control',
        validationMessage: 'tf-swal-validation',
        icon: 'tf-swal-icon',
    },
});

export function notifyError(error, fallback) {
    toast.error(errorMessage(error, fallback));
}

export function notifySuccess(message) {
    toast.success(message);
}

/** Themed confirm dialog. Resolves true/false. */
export async function confirmAction({
    title = 'Are you sure?',
    text = '',
    confirmText = 'Confirm',
    danger = false,
} = {}) {
    const result = await swal.fire({
        title,
        text,
        icon: danger ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonText: confirmText,
        customClass: {
            confirmButton: `btn px-4 ${danger ? 'btn-danger' : 'btn-primary'}`,
            cancelButton: 'btn btn-light px-4',
        },
    });
    return result.isConfirmed;
}
