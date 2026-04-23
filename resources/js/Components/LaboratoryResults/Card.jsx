export default function Card({ children, className = "" }) {
  return (
    <section
      className={`rounded-xl border border-slate-700 bg-slate-800 p-6 shadow-sm sm:p-8 ${className}`}
    >
      {children}
    </section>
  );
}
