import defaultTheme from "tailwindcss/defaultTheme";
import aspectRatio from "@tailwindcss/aspect-ratio";

export default {
	content: [
		"./vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php",
		"./storage/framework/views/*.php",
		"./resources/views/**/*.blade.php",
		"!./resources/views/vendor/**/*.blade.php",
		"./resources/js/**/*.jsx",
	],

	theme: {
		extend: {
			fontFamily: {
				poppins: ["Poppins", "sans-serif"],
				sans: ["Inter", ...defaultTheme.fontFamily.sans],
			},
			colors: {
				famedic: {
					light: "#009ad8",
					dark: "#26214e",
					darker: "#1E1A3D",
					lime: "#d5f278",
				},
			},
			borderRadius: {
				"4xl": "2rem",
			},
			keyframes: {
				"call-invite": {
					"0%, 100%": {
						opacity: "0.72",
						transform: "scale(1)",
					},
					"50%": {
						opacity: "1",
						transform: "scale(1.04)",
					},
				},
			},
			animation: {
				"call-invite": "call-invite 2.6s ease-in-out infinite",
			},
		},
	},
	plugins: [aspectRatio, require("@tailwindcss/typography")],
};
