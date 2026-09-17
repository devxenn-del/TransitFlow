/**
 * Renders a legal document's plain-text `content` into React nodes. A line
 * starting with one or more `#` followed by a space is a section heading;
 * everything else is paragraph text (blank lines separate paragraphs).
 * Deliberately dependency-free — no Markdown/HTML is stored or parsed,
 * matching how the Android app renders the same content natively.
 */
export function renderLegalContent(content) {
    if (!content) return null;

    return content.split('\n').reduce((nodes, line, i) => {
        const heading = /^#+\s+(.*)$/.exec(line.trim());
        if (heading) {
            nodes.push(<h2 className="h6 fw-bold mt-4 mb-2" key={i}>{heading[1]}</h2>);
        } else if (line.trim() !== '') {
            nodes.push(<p className="mb-2" key={i}>{line}</p>);
        }
        return nodes;
    }, []);
}
