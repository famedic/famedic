import { useCallback, useEffect, useRef, useState } from "react";

export default function OtpInput({
  length = 6,
  disabled = false,
  onComplete,
  resetKey,
}) {
  const [digits, setDigits] = useState(Array.from({ length }, () => ""));
  const refs = useRef([]);

  useEffect(() => {
    setDigits(Array.from({ length }, () => ""));
  }, [length, resetKey]);

  const value = digits.join("");
  const isComplete = digits.every((digit) => digit !== "");

  useEffect(() => {
    if (isComplete && value.length === length && onComplete) {
      onComplete(value);
    }
  }, [isComplete, value, length, onComplete]);

  const onChange = useCallback(
    (index, raw) => {
      const nextChar = raw.replace(/\D/g, "").slice(-1);
      setDigits((prev) => {
        const next = [...prev];
        next[index] = nextChar;
        return next;
      });

      if (nextChar && index < length - 1) {
        refs.current[index + 1]?.focus();
      }
    },
    [length]
  );

  const onKeyDown = useCallback(
    (index, event) => {
      if (event.key === "Backspace" && !digits[index] && index > 0) {
        refs.current[index - 1]?.focus();
      }
      if (event.key === "ArrowLeft" && index > 0) {
        event.preventDefault();
        refs.current[index - 1]?.focus();
      }
      if (event.key === "ArrowRight" && index < length - 1) {
        event.preventDefault();
        refs.current[index + 1]?.focus();
      }
    },
    [digits, length]
  );

  return (
    <div className="flex flex-nowrap justify-center gap-1.5 sm:gap-3" role="group" aria-label="Codigo OTP">
      {digits.map((digit, index) => (
        <input
          key={index}
          ref={(el) => {
            refs.current[index] = el;
          }}
          type="text"
          inputMode="numeric"
          autoComplete="one-time-code"
          maxLength={1}
          value={digit}
          disabled={disabled}
          onChange={(event) => onChange(index, event.target.value)}
          onKeyDown={(event) => onKeyDown(index, event)}
          onPaste={(event) => {
            event.preventDefault();
            const pasted = event.clipboardData.getData("text").replace(/\D/g, "").slice(0, length);
            if (!pasted) return;

            setDigits((prev) => {
              const next = [...prev];
              for (let i = 0; i < length; i += 1) {
                next[i] = pasted[i] ?? "";
              }
              return next;
            });

            refs.current[Math.min(pasted.length, length - 1)]?.focus();
          }}
          className="h-12 w-9 rounded-lg border-2 border-slate-600 bg-slate-900 text-center text-xl font-semibold text-white outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-400/60 disabled:cursor-not-allowed disabled:opacity-50 sm:h-14 sm:w-11 sm:text-2xl"
          aria-label={`Digito ${index + 1}`}
        />
      ))}
    </div>
  );
}
