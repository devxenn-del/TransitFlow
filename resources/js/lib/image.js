/**
 * Fits a logo inside a transparent square PNG so it shows whole inside the
 * round brand marks, and a large upload (a phone photo) shrinks to a few
 * hundred KB. Falls back to the original file if the browser can't decode it.
 */
export async function squareLogo(file, size = 512) {
    let bitmap;
    try {
        bitmap = await createImageBitmap(file);
    } catch {
        return file;
    }

    const scale = Math.min(size / bitmap.width, size / bitmap.height, 1);
    const width = Math.round(bitmap.width * scale);
    const height = Math.round(bitmap.height * scale);
    const side = Math.max(width, height);

    const canvas = document.createElement('canvas');
    canvas.width = side;
    canvas.height = side;
    const ctx = canvas.getContext('2d');
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(bitmap, (side - width) / 2, (side - height) / 2, width, height);
    bitmap.close();

    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
    if (!blob) {
        return file;
    }

    const name = file.name.replace(/\.[^.]+$/, '') + '.png';
    return new File([blob], name, { type: 'image/png' });
}
