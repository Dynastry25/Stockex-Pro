export default function Home() {
  return (
    <main
      style={{
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        justifyContent: 'center',
        minHeight: '100vh',
        fontFamily: 'sans-serif',
        background: '#f8fafc',
        padding: '2rem',
        textAlign: 'center',
      }}
    >
      <h1 style={{ color: '#164e63' }}>StockEx — Stock Exchange Management System</h1>
      <p style={{ color: '#334155', maxWidth: 640, lineHeight: 1.6 }}>
        This is the Next.js frontend scaffold. The live application is served by the PHP
        backend.
      </p>
      <a
        href="http://localhost:8092/"
        style={{
          marginTop: '1rem',
          padding: '0.75rem 1.5rem',
          background: '#164e63',
          color: '#fff',
          borderRadius: 8,
          textDecoration: 'none',
          fontWeight: 600,
        }}
      >
        Open StockEx (PHP app)
      </a>
    </main>
  )
}