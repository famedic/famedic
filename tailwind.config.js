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
		},
	},
	plugins: [aspectRatio, require("@tailwindcss/typography")],
};
